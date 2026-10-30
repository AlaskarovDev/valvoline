<?php

namespace App\Http\Controllers;

use App\Jobs\RebuildAnalytics;
use App\Models\StockCount;
use App\Models\StockDocument;
use App\Models\Warehouse;
use App\Models\Workplace;
use App\Services\AnalyticsRebuilder;
use App\Services\NumberingService;
use App\Services\PeriodLock;
use App\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Anbar sayımı: telefonla / skanerlə barkod oxutma, müqayisə və avtomatik artıq / əskik sənədləri.
 */
class StockCountController extends Controller
{
    public function index(): View
    {
        $counts = StockCount::query()->with('warehouse', 'creator')->latest('id')->limit(100)->get();
        $lines = DB::table('stock_count_lines')->whereIn('stock_count_id', $counts->pluck('id'))->groupBy('stock_count_id')
            ->selectRaw('stock_count_id, COUNT(*) AS n')->pluck('n', 'stock_count_id');

        return view('stockcounts.index', ['title' => __('Sayım'), 'counts' => $counts, 'lines' => $lines,
            'warehouses' => Warehouse::query()->where('is_active', true)->where('type', '<>', 'transit')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request, NumberingService $numbering): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'scope' => ['required', Rule::in(['full', 'partial'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        PeriodLock::assertOpen($data['date']);
        $workplace = Workplace::defaultIdFor($request->user());
        $count = StockCount::query()->create($data + ['workplace_id' => $workplace, 'created_by' => $request->user()->id,
            'doc_no' => $numbering->next('stock_count', ['date' => $data['date'], 'warehouse_id' => $data['warehouse_id'], 'workplace_id' => $workplace])]);

        return redirect()->route('stock-counts.show', $count);
    }

    public function show(StockCount $stockCount, StockCountService $counts): View
    {
        $count = $stockCount->load('warehouse', 'creator', 'surplus', 'shortage', 'canceller');

        return view('stockcounts.show', [
            'title' => __('Sayım').' № '.$count->doc_no, 'count' => $count, 'rows' => $counts->comparison($count),
            'products' => DB::table('products')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'barcode'])
                ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'barcode' => $p->barcode])->values(),
        ]);
    }

    /**
     * Skan və ya əl ilə miqdar (JSON): {code | product_id, qty, mode: add|set}.
     */
    public function scan(Request $request, StockCount $stockCount, StockCountService $counts): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:64'], 'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'min:0', 'max:10000000'], 'mode' => ['required', Rule::in(['add', 'set'])]]);
        $hit = ! empty($data['product_id']) ? ['product_id' => (int) $data['product_id'], 'factor' => 1.0] : $counts->resolve((string) ($data['code'] ?? ''));
        if (! $hit) {
            return response()->json(['ok' => false, 'message' => __('Tapılmadı').': '.($data['code'] ?? '')]);
        }
        try {
            $qty = $counts->record($stockCount, $hit['product_id'], (float) $data['qty'] * $hit['factor'], $data['mode'] === 'add', $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }
        $p = DB::table('products')->find($hit['product_id'], ['id', 'code', 'name']);

        return response()->json(['ok' => true, 'product_id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'counted' => $qty, 'factor' => $hit['factor']]);
    }

    public function complete(Request $request, StockCount $stockCount, StockCountService $counts): RedirectResponse
    {
        try {
            $made = $counts->complete($stockCount, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $docs = [];
        foreach (['surplus' => 'count_surplus', 'shortage' => 'count_shortage'] as $kind => $type) {
            if ($made[$kind]) {
                $doc = $stockCount->fresh()->{$kind};
                $docs[] = ['type' => StockDocument::typeLabels()[$type], 'no' => $doc->doc_no, 'url' => route('stock-docs.show', $doc)];
            }
        }
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        return redirect()->route('stock-counts.show', $stockCount)->with('popup', ['title' => __('Sayım tamamlandı'),
            'message' => $docs ? null : __('Fərq yoxdur — sənəd lazım olmadı.'), 'docs' => $docs]);
    }

    public function destroy(Request $request, StockCount $stockCount): RedirectResponse
    {
        if (! $stockCount->isOpen()) {
            return back()->with('error', __('Tamamlanmış sayım ləğv edilmir — yaranan artıq / əskik sənədlərini ləğv edin.'));
        }
        $stockCount->markCancelled($request->user()->id, $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null);

        return redirect()->route('stock-counts.index')->with('status', __('Sayım ləğv edildi.'));
    }
}
