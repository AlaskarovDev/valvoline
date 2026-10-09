<?php

namespace App\Http\Controllers;

use App\Imports\ImporterRegistry;
use App\Jobs\RebuildAnalytics;
use App\Models\ImportBatch;
use App\Services\AnalyticsRebuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Import axını: fayl seç → önizləmə (ilk 50 sətir + xəta siyahısı) → təsdiq → analytics:rebuild.
 * Hər import import_batches-ə yazılır və tam geri qaytarıla bilər.
 */
class ImportController extends Controller
{
    public function index(): View
    {
        $importers = ImporterRegistry::all();
        $last = [];
        foreach (array_keys($importers) as $type) {
            $last[$type] = ImportBatch::query()->where('file_type', $type)->where('status', 'done')->latest('imported_at')->first();
        }

        return view('import.index', [
            'title' => 'Import',
            'importers' => $importers,
            'last' => $last,
            'history' => ImportBatch::query()->with('user')->whereIn('status', ['done', 'rolled_back', 'replaced', 'failed'])->latest()->limit(60)->get(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'type' => ['nullable', 'string'],
        ]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $handle = fopen($file->getRealPath(), 'rb');
        $signature = $handle ? fread($handle, 2) : '';
        if ($handle) {
            fclose($handle);
        }
        // mimes:xlsx WPS / yenidən saxlanılmış faylları rədd edə bilir — uzantı + ZIP imzası yoxlanılır
        if ($extension !== 'xlsx' || $signature !== 'PK') {
            throw ValidationException::withMessages(['file' => 'Yalnız .xlsx (Excel 2007+) faylı qəbul olunur.']);
        }
        $type = $request->input('type') ?: ImporterRegistry::guess($file->getClientOriginalName());
        if ($type === null || ! array_key_exists($type, ImporterRegistry::all())) {
            throw ValidationException::withMessages(['type' => 'Fayl tipini seçin (fayl adından təyin olunmadı).']);
        }
        $path = $file->storeAs('imports', now()->format('Ymd_His').'_'.Str::random(6).'.xlsx', 'local');
        $batch = ImportBatch::query()->create([
            'file_type' => $type,
            'file_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'status' => 'preview',
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('import.preview', $batch);
    }

    public function preview(ImportBatch $batch): View|RedirectResponse
    {
        if ($batch->status !== 'preview') {
            return redirect()->route('import.index');
        }
        $importer = ImporterRegistry::make($batch->file_type);
        try {
            $preview = Cache::remember('import.preview.'.$batch->id, 1800, fn () => $importer->preview(Storage::disk('local')->path($batch->stored_path)));
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('import.index')->with('error', 'Fayl oxunmadı: '.$e->getMessage());
        }

        return view('import.preview', [
            'title' => 'Önizləmə: '.$batch->file_name,
            'batch' => $batch,
            'importer' => $importer,
            'preview' => $preview,
            'previous' => ImportBatch::query()->where('file_type', $batch->file_type)->where('status', 'done')->get(),
        ]);
    }

    public function confirm(Request $request, ImportBatch $batch): RedirectResponse
    {
        abort_if($batch->status !== 'preview', 409);
        $mode = $request->input('mode') === 'append' ? 'append' : 'replace';
        $importer = ImporterRegistry::make($batch->file_type);
        try {
            $batch = $importer->import(Storage::disk('local')->path($batch->stored_path), $batch, $mode);
        } catch (Throwable $e) {
            report($e);
            $batch->fill(['status' => 'failed', 'errors' => [['sheet' => '', 'row' => 0, 'message' => $e->getMessage()]]])->save();

            return redirect()->route('import.index')->with('error', 'Import alınmadı: '.$e->getMessage());
        }
        Cache::forget('import.preview.'.$batch->id);
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        return redirect()->route('import.index')->with('status', sprintf(
            '%s import edildi: %s sətir, %s gözləmədə, %s xəta. Hesablamalar yenilənir…',
            $batch->file_name, number_format($batch->rows_total, 0, ',', ' '), $batch->rows_pending, $batch->rows_error,
        ));
    }

    public function cancel(ImportBatch $batch): RedirectResponse
    {
        abort_if($batch->status !== 'preview', 409);
        Storage::disk('local')->delete($batch->stored_path);
        Cache::forget('import.preview.'.$batch->id);
        $batch->delete();

        return redirect()->route('import.index')->with('status', 'Import ləğv edildi.');
    }

    public function rollback(ImportBatch $batch): RedirectResponse
    {
        abort_if($batch->status !== 'done', 409);
        ImporterRegistry::make($batch->file_type)->rollback($batch);
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        return redirect()->route('import.index')->with('status', "«{$batch->file_name}» importu geri qaytarıldı. Hesablamalar yenilənir…");
    }

    public function rebuild(): RedirectResponse
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        return back()->with('status', 'Yenidən hesablama başladı.');
    }

    public function status(): JsonResponse
    {
        return response()->json(AnalyticsRebuilder::status());
    }
}
