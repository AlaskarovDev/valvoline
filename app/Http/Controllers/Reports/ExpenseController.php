<?php

namespace App\Http\Controllers\Reports;

use App\Reports\Table;
use App\Services\ProfitLossService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ExpenseController extends ReportController
{
    public function __invoke(Request $request, ProfitLossService $pnl): View|Response
    {
        $filters = $this->filters($request, 'this_year', 'VALVO');
        $granularity = $request->query('gran') === 'year' ? 'year' : 'month';
        $byCategory = $pnl->cashByCategory($filters, $granularity, 'expense');

        $columns = [];
        foreach ($byCategory as $values) {
            $columns = array_merge($columns, array_map('strval', array_keys($values)));
        }
        $columns = array_values(array_unique($columns));
        sort($columns);

        $categoryIds = DB::table('expense_categories')->pluck('id', 'name');
        $pivotRows = [];
        foreach ($byCategory as $category => $values) {
            $row = ['category' => $category, 'category_id' => $categoryIds[$category] ?? null, 'total' => array_sum($values)];
            foreach ($columns as $i => $col) {
                $row['c'.$i] = $values[$col] ?? 0.0;
            }
            $pivotRows[] = (object) $row;
        }
        $grand = array_sum(array_map(fn ($r) => $r->total, $pivotRows));
        foreach ($pivotRows as $row) {
            $row->share = $grand != 0 ? $row->total / $grand : null;
        }

        $pivot = Table::make('pivot', ($granularity === 'year' ? __('Kateqoriya × il') : __('Kateqoriya × ay')))
            ->column('category', __('Kateqoriya'), 'text', ['link' => fn ($r) => route('expenses.index', $filters->query(['cat' => $r->category_id, 'gran' => $granularity === 'year' ? 'year' : null]))]);
        // Xanaya klik → həmin rəqəmi yaradan kassa əməliyyatları (popup)
        $detail = fn (object $r, string $col) => abs((float) ($col === 'total' ? $r->total : $r->{'c'.array_search($col, $columns, true)})) > 0.004
            ? route('expenses.detail', $filters->query(['name' => $r->category, 'col' => $col, 'gran' => $granularity]))
            : null;
        foreach ($columns as $i => $col) {
            $pivot->column('c'.$i, $granularity === 'year' ? $col : Fmt::period($col), 'money', ['link' => fn ($r) => $detail($r, $col), 'modal' => true]);
        }
        $pivot->column('total', __('Cəmi'), 'money', ['class' => fn () => 'font-semibold', 'link' => fn ($r) => $detail($r, 'total'), 'modal' => true])
            ->column('share', __('Pay'), 'pct')
            ->source(collect($pivotRows))->sortBy('total')->perPage(100)->footer(auto: true)
            ->subtitle(__('Yalnız «Xərc» tipli və mənfəətə təsir edən əməliyyatlar · istiqamət: :direction · rəqəmə klik → əməliyyatlar', ['direction' => $filters->direction && $filters->direction !== 'all' ? $filters->direction : __('hamısı')]));

        $category = is_numeric($request->query('cat')) ? (int) $request->query('cat') : null;
        $list = DB::table('cash_transactions as c')
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 'c.expense_category_id')
            ->whereBetween('c.date', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->direction && $filters->direction !== 'all', fn ($q) => $q->where('c.direction', $filters->direction))
            ->when($category, fn ($q) => $q->where('c.expense_category_id', $category))
            ->when($request->query('profit') === 'yes', fn ($q) => $q->where('c.affects_profit', true))
            ->when($request->query('profit') === 'no', fn ($q) => $q->where('c.affects_profit', false))
            ->select('c.id', 'c.date', 'c.category_name', 'c.subcategory', 'c.description', 'c.income', 'c.expense', 'c.op_type', 'c.affects_profit', 'c.direction', 'c.note');
        $listTable = Table::make('cash', __('Kassa əməliyyatları'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'c.date'])
            ->column('category_name', __('Kateqoriya'), 'text', ['sql' => 'c.category_name'])
            ->column('subcategory', __('Alt kateqoriya'), 'text', ['sql' => 'c.subcategory'])
            ->column('description', __('Təsvir'), 'text', ['sql' => 'c.description'])
            ->column('income', __('Mədaxil'), 'money', ['sql' => 'c.income', 'class' => fn ($v) => $v > 0 ? 'pos' : ''])
            ->column('expense', __('Məxaric'), 'money', ['sql' => 'c.expense', 'class' => fn ($v) => $v > 0 ? 'neg' : ''])
            ->column('op_type', __('Tip'), 'text', ['sql' => 'c.op_type'])
            ->column('affects_profit', __('Mənfəətə təsir'), 'text', ['sql' => 'c.affects_profit', 'searchable' => false, 'format' => fn ($v) => $v ? '<span class="badge badge-primary">'.e(__('Bəli')).'</span>' : '<span class="badge">'.e(__('Xeyr')).'</span>'])
            ->column('direction', __('İstiqamət'), 'text', ['sql' => 'c.direction'])
            ->source($list)->sortBy('date')->footer(auto: true);

        if ($response = $this->exportIfRequested($request, [$pivot, $listTable], __('Xərclər'), $filters)) {
            return $response;
        }

        $top = array_slice(array_keys($byCategory), 0, 6);
        $chart = [
            'type' => 'bar',
            'stacked' => true,
            'categories' => array_map(fn ($c) => $granularity === 'year' ? $c : Fmt::period($c), $columns),
            'series' => array_merge(
                array_map(fn ($cat) => ['name' => $cat, 'data' => array_map(fn ($c) => round($byCategory[$cat][$c] ?? 0, 2), $columns)], $top),
                count($byCategory) > 6 ? [['name' => __('Digər'), 'data' => array_map(fn ($c) => round(array_sum(array_map(fn ($cat) => $byCategory[$cat][$c] ?? 0, array_slice(array_keys($byCategory), 6))), 2), $columns)]] : [],
            ),
        ];

        return view('reports.expenses', [
            'title' => __('Xərclər'),
            'filters' => $filters,
            'granularity' => $granularity,
            'category' => $category,
            'grand' => $grand,
            'chart' => $chart,
            'pivot' => $pivot, 'pivotRows' => $pivot->resolve($request),
            'listTable' => $listTable, 'listRows' => $listTable->resolve($request),
        ]);
    }
}
