<?php

namespace App\Http\Controllers;

use App\Jobs\RebuildAnalytics;
use App\Models\CustomerAlias;
use App\Services\AnalyticsRebuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AliasController extends Controller
{
    public function index(): View
    {
        return view('aliases.index', ['title' => 'Ad uyğunlaşdırma']);
    }

    public function destroy(CustomerAlias $alias): RedirectResponse
    {
        abort_if($alias->import_batch_id !== null, 403, 'Fayldan gələn alias yalnız yenidən import ilə dəyişir.');
        $alias->delete();
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        return back()->with('status', 'Alias silindi. Hesablamalar yenilənir.');
    }
}
