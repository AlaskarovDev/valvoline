<?php

namespace App\Http\Controllers\Reports;

use App\Services\ProfitLossService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Xərc pivotundakı bir xananın (kateqoriya × ay/il və ya cəmi) arxasındakı kassa əməliyyatları.
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

        $query = ProfitLossService::profitCash($filters, 'expense');
        $name === '—' ? $query->whereNull('c.category_name') : $query->where('c.category_name', $name);
        if ($col !== 'total') {
            $granularity === 'year'
                ? $query->where('c.period', 'like', substr($col, 0, 4).'-%')
                : $query->where('c.period', substr($col, 0, 7));
        }
        $rows = $query->orderBy('c.date')->orderBy('c.id')
            ->get(['c.id', 'c.date', 'c.subcategory', 'c.description', 'c.income', 'c.expense', 'c.op_type', 'c.direction', 'c.note', 'c.original_row', 'c.category_source']);

        $periodLabel = match (true) {
            $col === 'total' => $filters->label(),
            $granularity === 'year' => $col.' ili',
            default => Fmt::period($col),
        };

        return view($request->header('X-Modal') ? 'reports.expense-detail' : 'reports.expense-detail-page', [
            'title' => $name.' · '.$periodLabel,
            'name' => $name,
            'periodLabel' => $periodLabel,
            'direction' => $filters->direction && $filters->direction !== 'all' ? $filters->direction : 'bütün istiqamətlər',
            'rows' => $rows,
            'total' => $rows->sum(fn ($r) => (float) $r->expense - (float) $r->income),
            'bySub' => $rows->groupBy(fn ($r) => $r->subcategory ?: '—')
                ->map(fn ($g) => ['count' => $g->count(), 'sum' => $g->sum(fn ($r) => (float) $r->expense - (float) $r->income)])
                ->sortByDesc('sum'),
        ]);
    }
}
