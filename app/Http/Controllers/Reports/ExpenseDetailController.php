<?php

namespace App\Http\Controllers\Reports;

use App\Reports\ReportFilters;
use App\Services\FlowClassifier;
use App\Services\ProfitLossService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Xərc pivotundakı bir xananın (kateqoriya × ay/il və ya cəmi) arxasındakı əməliyyatlar:
 * kassa sətirləri və ya (rəsmi xərclər üçün) mühasibat Cash Flow-dakı bank ödənişləri.
 * Sorğu başlığında X-Modal varsa yalnız popup məzmunu, yoxdursa tam səhifə qaytarılır.
 */
class ExpenseDetailController extends ReportController
{
    public function __invoke(Request $request): View
    {
        $filters = $this->filters($request, 'this_year', 'VALVO');
        $name = (string) $request->query('name', '');
        $col = (string) $request->query('col', 'total');
        $granularity = $request->query('gran') === 'year' ? 'year' : 'month';
        $periodLabel = match (true) {
            $col === 'total' => $filters->label(),
            $granularity === 'year' => __(':year ili', ['year' => $col]),
            default => Fmt::period($col),
        };
        $page = $request->header('X-Modal') ? null : 'reports.expense-detail-page';

        if ($request->query('src') === 'bank') {
            return $this->bank($filters->fromPeriod(), $filters->toPeriod(), $name, $col, $granularity, $periodLabel, $page);
        }

        $query = ProfitLossService::profitCash($filters, 'expense');
        $name === '—' ? $query->whereNull('c.category_name') : $query->where('c.category_name', $name);
        if ($col !== 'total') {
            $granularity === 'year'
                ? $query->where('c.period', 'like', substr($col, 0, 4).'-%')
                : $query->where('c.period', substr($col, 0, 7));
        }
        $rows = $query->orderBy('c.date')->orderBy('c.id')
            ->get(['c.id', 'c.date', 'c.subcategory', 'c.description', 'c.income', 'c.expense', 'c.op_type', 'c.direction', 'c.note', 'c.original_row', 'c.category_source']);

        return view($page ?? 'reports.expense-detail', [
            'partial' => 'reports.expense-detail',
            'title' => $name.' · '.$periodLabel,
            'name' => $name,
            'periodLabel' => $periodLabel,
            'direction' => $filters->direction && $filters->direction !== 'all' ? $filters->direction : __('bütün istiqamətlər'),
            'rows' => $rows,
            'total' => $rows->sum(fn ($r) => (float) $r->expense - (float) $r->income),
            'bySub' => $rows->groupBy(fn ($r) => $r->subcategory ?: '—')
                ->map(fn ($g) => ['count' => $g->count(), 'sum' => $g->sum(fn ($r) => (float) $r->expense - (float) $r->income)])
                ->sortByDesc('sum'),
        ]);
    }

    /**
     * Rəsmi xərc qrupunun bank ödənişləri (kontragent × ay) — mühasibat Cash Flow-dan.
     */
    private function bank(string $from, string $to, string $group, string $col, string $granularity, string $periodLabel, ?string $page): View
    {
        [$from, $to] = match (true) {
            $col === 'total' => [$from, $to],
            $granularity === 'year' => [max($from, $col.'-01'), min($to, $col.'-12')],
            default => [$col, $col],
        };
        $cell = new ReportFilters(Carbon::parse($from.'-01')->startOfMonth(), Carbon::parse($to.'-01')->endOfMonth(), 'custom', direction: 'VALVO');
        $rows = app(ProfitLossService::class)->bankPaymentLines($cell)->where('pnl_group', $group)->values();

        return view($page ?? 'reports.expense-detail-bank', [
            'partial' => 'reports.expense-detail-bank',
            'title' => FlowClassifier::groupLabel($group).' · '.$periodLabel,
            'name' => FlowClassifier::groupLabel($group),
            'periodLabel' => $periodLabel,
            'rows' => $rows,
            'total' => (float) $rows->sum('amount'),
            'byCounterparty' => $rows->groupBy('counterparty')->map(fn ($g) => (float) $g->sum('amount'))->sortDesc(),
            'year' => substr($to, 0, 4),
        ]);
    }
}
