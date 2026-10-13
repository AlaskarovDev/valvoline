<?php

namespace App\Http\Controllers\Reports;

use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Reports\Table;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marjinallıq: mal, məhsul qrupu, kateqoriya, müştəri, menecer üzrə satış, maya, brüt mənfəət, marja, orta endirim.
 */
class MarginController extends ReportController
{
    public const CHART_ITEMS = 20;

    /**
     * Marja qrupları: rəng tokeni => [aşağı hədd, ad].
     *
     * @return array<string, array{0: float, 1: string}>
     */
    public static function marginBands(): array
    {
        return [
            '--positive' => [0.35, '35 %+'],
            '--chart-2' => [0.20, '20–35 %'],
            '--warning' => [0.0, '0–20 %'],
            '--negative' => [-INF, __('zərər')],
        ];
    }

    public static function marginColor(?float $margin): string
    {
        foreach (self::marginBands() as $color => [$min]) {
            if (($margin ?? 0) >= $min) {
                return $color;
            }
        }

        return '--negative';
    }

    /**
     * @return array<string, string>
     */
    public static function dimensions(): array
    {
        return ['product' => __('Mal (kod)'), 'group' => __('Məhsul qrupu'), 'category' => __('Kateqoriya'), 'customer' => __('Müştəri'), 'manager' => __('Menecer')];
    }

    public function __invoke(Request $request): View|Response
    {
        $filters = $this->filters($request);
        $dimension = array_key_exists((string) $request->query('dim'), self::dimensions()) ? $request->query('dim') : 'product';
        $rows = $this->rows($filters, $dimension);

        $table = Table::make('margin', __('Marjinallıq').' — '.self::dimensions()[$dimension])
            ->column('name', self::dimensions()[$dimension], 'text', ['link' => fn ($r) => $r->link, 'format' => fn ($v, $r) => e($v).($r->estimated ? ' <span class="warn" title="'.e(__('Maya təxminidir')).'">⚠</span>' : '')]);
        if ($dimension === 'product') {
            $table->column('code', __('Kod'));
        }
        $table->column('qty', __('Miqdar'), 'qty')
            ->column('liters', __('Litr'), 'qty')
            ->column('sales', __('Satış'), 'money')
            ->column('returns', __('Qaytarma'), 'money')
            ->column('net_sales', __('Xalis satış'), 'money')
            ->column('cogs', __('Maya'), 'money')
            ->column('gross_profit', __('Brüt mənfəət'), 'money', ['class' => fn ($v) => $v < 0 ? 'neg' : ''])
            ->column('margin', __('Marja'), 'pct', ['class' => fn ($v) => $v !== null && $v < 0 ? 'neg font-semibold' : 'font-semibold'])
            ->column('discount', __('Orta endirim'), 'pct')
            ->source($rows)->sortBy('net_sales')->perPage(100)->footer(auto: true)
            ->rowClass(fn ($r) => $r->gross_profit < 0 ? 'row-neg' : '');

        if ($response = $this->exportIfRequested($request, [$table], __('Marjinallıq'), $filters)) {
            return $response;
        }
        $paginated = $table->resolve($request);
        $footer = $table->footerValues();
        if ($footer !== null) {
            $footer['margin'] = abs($footer['net_sales']) > 0.005 ? $footer['gross_profit'] / $footer['net_sales'] : null;
            $footer['discount'] = null;
            $table->footer($footer);
        }

        $top = $rows->filter(fn ($r) => $r->net_sales > 0)->sortByDesc('net_sales')->take(self::CHART_ITEMS)->values();
        $chart = [
            'type' => 'bar',
            'horizontal' => true,
            'distributed' => true,
            'categories' => $top->map(fn ($r) => mb_strimwidth($r->name, 0, 42, '…'))->all(),
            'series' => [['name' => __('Xalis satış'), 'data' => $top->map(fn ($r) => round($r->net_sales, 2))->all()]],
            'colors' => $top->map(fn ($r) => self::marginColor($r->margin))->all(),
            'barLabels' => $top->map(fn ($r) => Fmt::pct($r->margin))->all(),
            'links' => $top->map(fn ($r) => $r->link)->all(),
        ];

        return view('reports.margin', [
            'title' => __('Marjinallıq'),
            'filters' => $filters,
            'dimension' => $dimension,
            'table' => $table,
            'rows' => $paginated,
            'chart' => $chart,
            'chartHeight' => max(220, 60 + $top->count() * 28),
        ]);
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(ReportFilters $filters, string $dimension): Collection
    {
        $query = SalesSummaryQuery::base($filters);
        [$key, $name, $extra] = match ($dimension) {
            'group' => ['p.product_group_id', 'g.name', ''],
            'category' => ['p.category_id', 'cat.name', ''],
            'customer' => ['s.customer_id', 'c.name', ''],
            'manager' => ['s.manager_id', 'm.name', ''],
            default => ['s.product_id', 'p.name', ', p.code AS code, p.cost_estimated AS estimated'],
        };
        match ($dimension) {
            'group' => $query->leftJoin('product_groups as g', 'g.id', '=', 'p.product_group_id'),
            'category' => $query->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id'),
            'customer' => $query->leftJoin('customers as c', 'c.id', '=', 's.customer_id'),
            'manager' => $query->leftJoin('managers as m', 'm.id', '=', 's.manager_id'),
            default => null,
        };
        $groupBy = [DB::raw($key), DB::raw($name)];
        if ($dimension === 'product') {
            $groupBy[] = 'p.code';
            $groupBy[] = 'p.cost_estimated';
        }

        return $query->groupBy(...$groupBy)
            ->selectRaw("{$key} AS id, {$name} AS name{$extra}, ".SalesSummaryQuery::METRICS)
            ->get()
            ->map(function ($r) use ($dimension, $filters) {
                $r = SalesSummaryQuery::derive($r);
                $r->name ??= '— ('.__('təyin edilməyib').')';
                $r->estimated = (bool) ($r->estimated ?? false);
                $r->link = $r->id === null ? null : match ($dimension) {
                    'product' => route('movements.product', $r->id),
                    'customer' => route('customers.show', $r->id),
                    'group' => route('margin.index', $filters->query(['group' => $r->id, 'dim' => 'product'])),
                    'category' => route('margin.index', $filters->query(['category' => $r->id, 'dim' => 'product'])),
                    'manager' => route('margin.index', $filters->query(['manager' => $r->id, 'dim' => 'customer'])),
                };

                return $r;
            })
            ->filter(fn ($r) => abs($r->sales) > 0.001 || abs($r->returns) > 0.001 || abs($r->cogs) > 0.001);
    }
}
