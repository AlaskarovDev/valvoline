<?php

namespace App\Http\Controllers\Reports;

use App\Reports\Table;
use App\Services\FlowClassifier;
use App\Services\ProfitLossService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xərclər: kassa (08 faylı) və rəsmi xərclər (mühasibat Cash Flow-dan bank ödənişləri) bir pivotda.
 * Rəsmi xərclər kassada olmayan, yalnız mühasibatda görünən ödənişlərdir (əmək haqqı, DSMF, icarə …).
 */
class ExpenseController extends ReportController
{
    public const SOURCES = ['all', 'cash', 'bank'];

    public function __invoke(Request $request, ProfitLossService $pnl): View|Response
    {
        $filters = $this->filters($request, 'this_year', 'VALVO');
        $granularity = $request->query('gran') === 'year' ? 'year' : 'month';
        $source = in_array($request->query('src'), self::SOURCES, true) ? (string) $request->query('src') : 'all';
        $cash = $source === 'bank' ? [] : $pnl->cashByCategory($filters, $granularity, 'expense');
        $bank = $source === 'cash' ? [] : $pnl->bankExpensesByGroup($filters, $granularity);

        $columns = [];
        foreach (array_merge(array_values($cash), array_values($bank)) as $values) {
            $columns = array_merge($columns, array_map('strval', array_keys($values)));
        }
        $columns = array_values(array_unique($columns));
        sort($columns);

        $categoryIds = DB::table('expense_categories')->pluck('id', 'name');
        $pivotRows = [];
        foreach ([['cash', $cash], ['bank', $bank]] as [$kind, $set]) {
            foreach ($set as $name => $values) {
                $row = [
                    'category' => $kind === 'bank' ? FlowClassifier::groupLabel($name) : $name,
                    'name' => $name,
                    'source' => $kind,
                    'category_id' => $kind === 'cash' ? ($categoryIds[$name] ?? null) : null,
                    'total' => array_sum($values),
                ];
                foreach ($columns as $i => $col) {
                    $row['c'.$i] = $values[$col] ?? 0.0;
                }
                $pivotRows[] = (object) $row;
            }
        }
        $grand = array_sum(array_map(fn ($r) => $r->total, $pivotRows));
        foreach ($pivotRows as $row) {
            $row->share = $grand != 0 ? $row->total / $grand : null;
        }
        $cashTotal = array_sum(array_map('array_sum', $cash));
        $bankTotal = array_sum(array_map('array_sum', $bank));

        $year = substr($filters->toPeriod(), 0, 4);
        $sourceBadge = fn ($v) => $v === 'bank'
            ? '<span class="badge badge-primary" title="'.e(__('Mühasibat faylındakı bank ödənişi (Cash Flow)')).'">'.e(__('Rəsmi')).'</span>'
            : '<span class="badge">'.e(__('Kassa')).'</span>';
        $pivot = Table::make('pivot', ($granularity === 'year' ? __('Kateqoriya × il') : __('Kateqoriya × ay')))
            ->column('category', __('Kateqoriya'), 'text', ['link' => fn ($r) => $r->source === 'bank'
                ? route('accounting.cashflow', ['year' => $year, 'type' => FlowClassifier::EXPENSE])
                : route('expenses.index', $filters->query(['cat' => $r->category_id, 'gran' => $granularity === 'year' ? 'year' : null, 'src' => $source === 'all' ? null : $source]))])
            ->column('source', __('Mənbə'), 'text', ['searchable' => false, 'format' => $sourceBadge]);
        // Xanaya klik → həmin rəqəmi yaradan əməliyyatlar (popup): kassa sətirləri və ya bank ödənişləri
        $detail = fn (object $r, string $col) => abs((float) ($col === 'total' ? $r->total : $r->{'c'.array_search($col, $columns, true)})) > 0.004
            ? route('expenses.detail', $filters->query(['name' => $r->name, 'col' => $col, 'gran' => $granularity, 'src' => $r->source]))
            : null;
        foreach ($columns as $i => $col) {
            $pivot->column('c'.$i, $granularity === 'year' ? $col : Fmt::period($col), 'money', ['link' => fn ($r) => $detail($r, $col), 'modal' => true]);
        }
        $pivot->column('total', __('Cəmi'), 'money', ['class' => fn () => 'font-semibold', 'link' => fn ($r) => $detail($r, 'total'), 'modal' => true])
            ->column('share', __('Pay'), 'pct')
            ->source(collect($pivotRows))->sortBy('total')->perPage(100)->footer(auto: true)
            ->subtitle(__('Kassa: «Xərc» tipli və mənfəətə təsir edən əməliyyatlar · Rəsmi: mühasibat faylında bankdan ödənilmiş əməliyyat xərcləri · istiqamət: :direction · rəqəmə klik → əməliyyatlar', ['direction' => $filters->direction && $filters->direction !== 'all' ? $filters->direction : __('hamısı')]));

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

        // qrafik: ən böyük 6 sətir (kassa və rəsmi ayrıca), qalanı «Digər»
        $series = collect($pivotRows)->sortByDesc('total')->values();
        $label = fn ($r) => $r->source === 'bank' ? $r->category.' ('.__('rəsmi').')' : $r->category;
        $values = fn ($r) => array_map(fn ($i) => round($r->{'c'.$i}, 2), array_keys($columns));
        $rest = $series->slice(6);
        $chart = [
            'type' => 'bar',
            'stacked' => true,
            'categories' => array_map(fn ($c) => $granularity === 'year' ? $c : Fmt::period($c), $columns),
            'series' => array_merge(
                $series->take(6)->map(fn ($r) => ['name' => $label($r), 'data' => $values($r)])->all(),
                $rest->isNotEmpty() ? [['name' => __('Digər'), 'data' => array_map(fn ($i) => round($rest->sum(fn ($r) => $r->{'c'.$i}), 2), array_keys($columns))]] : [],
            ),
        ];

        return view('reports.expenses', [
            'title' => __('Xərclər'),
            'filters' => $filters,
            'granularity' => $granularity,
            'source' => $source,
            'category' => $category,
            'grand' => $grand,
            'cashTotal' => $cashTotal,
            'bankTotal' => $bankTotal,
            'chart' => $chart,
            'pivot' => $pivot, 'pivotRows' => $pivot->resolve($request),
            'listTable' => $listTable, 'listRows' => $listTable->resolve($request),
        ]);
    }
}
