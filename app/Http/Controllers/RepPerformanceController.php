<?php

namespace App\Http\Controllers;

use App\Services\RepPerformanceService;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Plan–fakt və bonus: nümayəndələr üzrə aylıq hədəf, icra, proqnoz və bonus.
 */
class RepPerformanceController extends Controller
{
    public function index(Request $request, RepPerformanceService $performance): View
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('period')) ? $request->query('period') : now()->format('Y-m');
        $rows = $performance->month($period, $request->user()->restrictedManagerId());

        return view('reps.performance', [
            'title' => __('Plan–fakt və bonus'), 'period' => $period, 'rows' => $rows, 'rules' => RepPerformanceService::rules(),
            'canEdit' => $request->user()->can('sales.manage'), 'bases' => RepPerformanceService::baseLabels(),
            'totals' => ['sales_target' => $rows->sum('sales_target'), 'sales' => $rows->sum('sales'), 'collections' => $rows->sum('collections'),
                'profit' => $rows->sum('profit'), 'forecast' => $rows->sum('forecast'), 'bonus' => $rows->sum('bonus')],
        ]);
    }

    public function targets(Request $request, RepPerformanceService $performance): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'targets' => ['array'], 'targets.*.sales' => ['nullable', 'numeric', 'min:0', 'max:1000000000'], 'targets.*.collections' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);
        foreach ($data['targets'] ?? [] as $managerId => $t) {
            $performance->saveTarget((int) $managerId, $data['period'], isset($t['sales']) ? (float) $t['sales'] : null, isset($t['collections']) ? (float) $t['collections'] : null);
        }

        return back()->with('status', __('Hədəflər yadda saxlanıldı.'));
    }

    public function rules(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base' => ['required', Rule::in(array_keys(RepPerformanceService::baseLabels()))],
            'measure' => ['required', Rule::in(['sales', 'collections'])],
            'tiers' => ['array', 'max:10'], 'tiers.*.from' => ['nullable', 'numeric', 'min:0', 'max:1000'], 'tiers.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $tiers = collect($data['tiers'] ?? [])->filter(fn ($t) => isset($t['from'], $t['rate']))->values()->all();
        AppSettings::set(RepPerformanceService::SETTING, json_encode(['base' => $data['base'], 'measure' => $data['measure'], 'tiers' => $tiers]), $request->user()->id);

        return back()->with('status', __('Bonus qaydaları yadda saxlanıldı.'));
    }
}
