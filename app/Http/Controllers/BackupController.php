<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tənzimləmələr → Ehtiyat nüsxələri: siyahı, «indi yarat», yükləmə.
 */
class BackupController extends Controller
{
    public function index(BackupService $backups): View
    {
        return view('settings.backups', ['title' => __('Ehtiyat nüsxələri'), 'backups' => $backups->list(), 'keep' => BackupService::KEEP]);
    }

    public function store(BackupService $backups): RedirectResponse
    {
        $result = $backups->create();

        return redirect()->route('backups.index')->with('popup', ['title' => __('Ehtiyat nüsxəsi yaradıldı'),
            'message' => __(':name — :tables cədvəl, :rows sətir.', ['name' => $result['name'], 'tables' => $result['tables'], 'rows' => number_format($result['rows'], 0, ',', ' ')])]);
    }

    public function download(string $file, BackupService $backups): BinaryFileResponse
    {
        $path = $backups->path($file);
        abort_if($path === null, 404);

        return response()->download($path, $file, ['Content-Type' => 'application/gzip']);
    }
}
