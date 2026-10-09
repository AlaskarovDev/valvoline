<?php

namespace App\Http\Controllers\Reports;

use App\Reports\SalesSummaryQuery;
use App\Reports\Table;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aylıq / illik pivot: sətirlərdə ay (və ya il), sütunlarda menecer / kateqoriya / seriya.
 */
class SalesController extends ReportController
{
    public const DIMENSIONS = ['manager' => 'Menecer', 'category' => 'Kateqoriya', 'series' => 'Seriya', 'none' => 'Yalnız cəmi'];

    public const MEASURES = ['net_sales' => 'Xalis satış, ₼', 'gross_profit' => 'Brüt mənfəət, ₼', 'liters' => 'Həcm, litr', 'qty' => 'Miqdar, ədəd'];

    public function __invoke(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_year');
        $granularity = $request->query('gran') === 'year' ? 'year' : 'month';
        $dimension = array_key_exists($request->query('dim'), self::DIMENSIONS) ? $request->query('dim') : 'manager';
        $measure = array_key_exists($request->query('measure'), self::MEASURES) ? $request->query('measure') : 'net_sales';

        $periodExpr = $granularity === 'year' ? 'SUBSTR(s.period, 1, 4)' : 's.period';
        $query = SalesSummaryQuery::base($filters);
        [$dimExpr, $dimLabel] = match ($dimension) {
            'manager' => ['COALESCE(m.name, \'—\')', 'Menecer'],
            'category' => ['COALESCE(cat.name, \'Təyin edilməyib\')', 'Kateqoriya'],
            'series' => ['COALESCE(p.series, \'—\')', 'Seriya'],
            default => ["'Cəmi'", ''],
        };
        if ($dimension === 'manager') {
            $query->leftJoin('managers as m', 'm.id', '=', 's.manager_id');
        }
        if ($dimension === 'category') {
            $query->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id');
        }
        $data = $query->groupBy(DB::raw($periodExpr), DB::raw($dimExpr))
            ->selectRaw("{$periodExpr} AS col_period, {$dimExpr} AS dim, ".SalesSummaryQuery::METRICS)
            ->get()->map(fn ($r) => SalesSummaryQuery::derive($r));

        $dims = $data->groupBy('dim')->map(fn ($g) => $g->sum($measure))->sortDesc()->keys()->all();
        $periods = $data->pluck('col_period')->unique()->sort()->values()->all();
        $pivot = [];
        foreach ($periods as $period) {
            $row = ['period' => $period, 'label' => $granularity === 'year' ? (string) $period : Fmt::period($period), 'total' => 0.0, 'total_net' => 0.0, 'total_profit' => 0.0];
            foreach ($dims as $i => $dim) {
                $row['d'.$i] = 0.0;
            }
            foreach ($data->where('col_period', $period) as $cell) {
                $index = array_search($cell->dim, $dims, true);
                $row['d'.$index] = $cell->{$measure};
                $row['total'] += $cell->{$measure};
                $row['total_net'] += $cell->net_sales;
                $row['total_profit'] += $cell->gross_profit;
            }
            $row['margin'] = abs($row['total_net']) > 0.005 ? $row['total_profit'] / $row['total_net'] : null;
            $pivot[] = (object) $row;
        }

        $type = in_array($measure, ['liters', 'qty'], true) ? 'qty' : 'money';
        $table = Table::make('pivot', ($granularity === 'year' ? 'İllik' : 'Aylıq').' — '.self::MEASURES[$measure])
            ->column('label', $granularity === 'year' ? 'İl' : 'Ay', 'text', ['sortable' => false, 'searchable' => false, 'link' => fn ($r) => $granularity === 'month'
                ? route('orders.index', $filters->query(['preset' => null, 'from' => $r->period.'-01', 'to' => date('Y-m-t', strtotime($r->period.'-01'))])) : null]);
        foreach ($dims as $i => $dim) {
            $table->column('d'.$i, (string) $dim, $type, ['sortable' => false]);
        }
        $table->column('total', 'Cəmi', $type, ['sortable' => false, 'class' => fn () => 'font-semibold']);
        if ($measure === 'net_sales' || $measure === 'gross_profit') {
            $table->column('margin', 'Marja', 'pct', ['sortable' => false]);
        }
        $table->source(collect($pivot))->perPage(120)->footer(auto: true)->withoutSearch();
        if ($dimLabel !== '') {
            $table->subtitle('Sütunlar: '.$dimLabel);
        }

        if ($response = $this->exportIfRequested($request, [$table], 'Satış hesabatı', $filters)) {
            return $response;
        }
        $rows = $table->resolve($request);
        $footer = $table->footerValues();
        if ($footer !== null && isset($footer['total'])) {
            $totals = collect($pivot);
            $net = $totals->sum('total_net');
            $footer['margin'] = abs($net) > 0.005 ? $totals->sum('total_profit') / $net : null;
            $table->footer($footer);
        }

        $chart = [
            'type' => 'bar',
            'stacked' => count($dims) > 1,
            'format' => $type === 'qty' ? 'number' : 'money',
            'categories' => array_map(fn ($r) => $r->label, $pivot),
            'series' => array_map(fn ($i, $dim) => ['name' => (string) $dim, 'data' => array_map(fn ($r) => round($r->{'d'.$i}, 2), $pivot)], array_keys(array_slice($dims, 0, 8)), array_slice($dims, 0, 8)),
            'columnWidth' => '55%',
        ];

        return view('reports.sales', compact('filters', 'table', 'rows', 'chart', 'granularity', 'dimension', 'measure') + ['title' => 'Satış hesabatı']);
    }
}
