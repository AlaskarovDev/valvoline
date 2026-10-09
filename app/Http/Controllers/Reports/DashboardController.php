<?php

namespace App\Http\Controllers\Reports;

use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Services\ProfitLossService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends ReportController
{
    public function __invoke(Request $request): View
    {
        $filters = $this->filters($request, 'this_month', 'VALVO');
        $isAdmin = $request->user()->isAdmin();

        $current = SalesSummaryQuery::totals($filters);
        $previous = SalesSummaryQuery::totals(SalesSummaryQuery::previousYear($filters));
        $orders = $this->orderCount($filters);
        $previousOrders = $this->orderCount(SalesSummaryQuery::previousYear($filters));

        $expenses = $isAdmin ? (float) ProfitLossService::profitCash($filters, 'expense')->sum(DB::raw('c.expense - c.income')) : null;
        $otherIncome = $isAdmin ? (float) ProfitLossService::profitCash($filters, 'income')->sum(DB::raw('c.income - c.expense')) : null;
        $prevFilters = SalesSummaryQuery::previousYear($filters);
        $prevExpenses = $isAdmin ? (float) ProfitLossService::profitCash($prevFilters, 'expense')->sum(DB::raw('c.expense - c.income')) : null;
        $prevIncome = $isAdmin ? (float) ProfitLossService::profitCash($prevFilters, 'income')->sum(DB::raw('c.income - c.expense')) : null;

        $debt = DB::table('customer_balances as b')->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->where('c.type', 'customer')->where('b.balance', '>', 0)
            ->when($filters->managerId, fn ($q) => $q->where('c.manager_id', $filters->managerId))
            ->selectRaw('SUM(b.balance) AS total, COUNT(*) AS cnt')->first();
        $stock = DB::table('products')->selectRaw('SUM(CASE WHEN stock_qty > 0 THEN stock_qty * COALESCE(current_avg_cost, 0) ELSE 0 END) AS value, SUM(CASE WHEN stock_qty > 0 THEN stock_qty ELSE 0 END) AS qty')->first();

        return view('reports.dashboard', [
            'title' => 'İdarə paneli',
            'filters' => $filters,
            'isAdmin' => $isAdmin,
            'kpi' => [
                'net_sales' => [$current->net_sales, Fmt::delta($current->net_sales, $previous->net_sales)],
                'gross_profit' => [$current->gross_profit, Fmt::delta($current->gross_profit, $previous->gross_profit)],
                'margin' => [$current->margin, $previous->margin],
                'expenses' => [$expenses, $isAdmin ? Fmt::delta($expenses, $prevExpenses) : null],
                'net_profit' => $isAdmin ? [
                    $current->gross_profit + $otherIncome - $expenses,
                    Fmt::delta($current->gross_profit + $otherIncome - $expenses, $previous->gross_profit + $prevIncome - $prevExpenses),
                ] : null,
                'debt' => [(float) ($debt->total ?? 0), (int) ($debt->cnt ?? 0)],
                'stock' => [(float) ($stock->value ?? 0), (float) ($stock->qty ?? 0)],
                'orders' => [$orders, Fmt::delta($orders, $previousOrders)],
            ],
            'trend' => $this->trend($filters),
            'categories' => $this->categoryShare($filters),
            'topProducts' => $this->topProducts($filters),
            'topDebtors' => $this->topDebtors($filters),
            'managers' => $this->managerSales($filters),
        ]);
    }

    private function orderCount(ReportFilters $filters): int
    {
        return (int) DB::table('sales_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->whereBetween('l.date', [$filters->fromDate(), $filters->toDate()])
            ->where('l.op_type', 'sale')
            ->tap(fn ($q) => SalesSummaryQuery::applyDimensions($q, $filters, 'l', 'p'))
            ->distinct()->count('l.sales_order_id');
    }

    /**
     * Son 24 ay: xalis satış, brüt mənfəət (sütun) və marja (xətt).
     *
     * @return array<string, mixed>
     */
    private function trend(ReportFilters $filters): array
    {
        $end = $filters->to->copy()->startOfMonth();
        $range = clone $filters;
        $range->from = $end->copy()->subMonthsNoOverflow(23)->startOfMonth();
        $range->to = $end->copy()->endOfMonth();
        $rows = SalesSummaryQuery::base($range)->groupBy('s.period')->selectRaw('s.period, '.SalesSummaryQuery::METRICS)->get()->keyBy('period');
        $periods = [];
        for ($c = $range->from->copy(); $c->lessThanOrEqualTo($end); $c->addMonthNoOverflow()) {
            $periods[] = $c->format('Y-m');
        }
        $sales = $profit = $margin = [];
        foreach ($periods as $p) {
            $r = SalesSummaryQuery::derive($rows[$p] ?? (object) []);
            $sales[] = round($r->net_sales, 2);
            $profit[] = round($r->gross_profit, 2);
            $margin[] = $r->margin !== null ? round($r->margin, 4) : null;
        }

        return [
            'type' => 'line',
            'categories' => array_map(fn ($p) => Fmt::period($p), $periods),
            'series' => [
                ['name' => 'Xalis satış', 'type' => 'column', 'data' => $sales],
                ['name' => 'Brüt mənfəət', 'type' => 'column', 'data' => $profit],
                ['name' => 'Marja %', 'type' => 'line', 'data' => $margin],
            ],
            'dualAxis' => true,
            'secondFormat' => 'pct',
            'strokeWidths' => [0, 0, 2.5],
            'markers' => true,
            'links' => array_map(fn ($p) => route('orders.index', $filters->query(['from' => $p.'-01', 'to' => Carbon::parse($p.'-01')->endOfMonth()->toDateString(), 'preset' => null])), $periods),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryShare(ReportFilters $filters): array
    {
        $rows = SalesSummaryQuery::base($filters)
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->groupBy('cat.id', 'cat.name')
            ->selectRaw('cat.id, cat.name, '.SalesSummaryQuery::METRICS)
            ->get()->map(fn ($r) => SalesSummaryQuery::derive($r))
            ->filter(fn ($r) => $r->net_sales > 0)->sortByDesc('net_sales')->values();

        return [
            'type' => 'donut',
            'labels' => $rows->map(fn ($r) => $r->name ?? 'Təyin edilməyib')->all(),
            'series' => $rows->map(fn ($r) => round($r->net_sales, 2))->all(),
            'links' => $rows->map(fn ($r) => $r->id ? route('margin.index', $filters->query(['category' => $r->id, 'dim' => 'product'])) : null)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function topProducts(ReportFilters $filters): array
    {
        $rows = SalesSummaryQuery::base($filters)
            ->whereNotNull('s.product_id')
            ->groupBy('s.product_id', 'p.name', 'p.code')
            ->selectRaw('s.product_id, p.name, p.code, '.SalesSummaryQuery::METRICS)
            ->get()->map(fn ($r) => SalesSummaryQuery::derive($r))->sortByDesc('net_sales')->take(10)->values();

        return [
            'type' => 'bar',
            'horizontal' => true,
            'categories' => $rows->map(fn ($r) => mb_strimwidth($r->name ?? $r->code, 0, 38, '…'))->all(),
            'series' => [['name' => 'Xalis satış', 'data' => $rows->map(fn ($r) => round($r->net_sales, 2))->all()]],
            'links' => $rows->map(fn ($r) => route('movements.product', $r->product_id))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function topDebtors(ReportFilters $filters): array
    {
        $rows = DB::table('customer_balances as b')->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->where('c.type', 'customer')->where('b.balance', '>', 0)
            ->when($filters->managerId, fn ($q) => $q->where('c.manager_id', $filters->managerId))
            ->orderByDesc('b.balance')->limit(10)->get(['c.id', 'c.name', 'b.balance']);

        return [
            'type' => 'bar',
            'horizontal' => true,
            'colors' => ['--chart-6'],
            'categories' => $rows->map(fn ($r) => mb_strimwidth($r->name, 0, 38, '…'))->all(),
            'series' => [['name' => 'Borc', 'data' => $rows->map(fn ($r) => round((float) $r->balance, 2))->all()]],
            'links' => $rows->map(fn ($r) => route('customers.show', $r->id))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function managerSales(ReportFilters $filters): array
    {
        $rows = SalesSummaryQuery::base($filters)
            ->leftJoin('managers as m', 'm.id', '=', 's.manager_id')
            ->groupBy('s.manager_id', 'm.name')
            ->selectRaw('s.manager_id, m.name, '.SalesSummaryQuery::METRICS)
            ->get()->map(fn ($r) => SalesSummaryQuery::derive($r))->sortByDesc('net_sales')->values();

        return [
            'type' => 'bar',
            'categories' => $rows->map(fn ($r) => $r->name ?? '—')->all(),
            'series' => [
                ['name' => 'Xalis satış', 'data' => $rows->map(fn ($r) => round($r->net_sales, 2))->all()],
                ['name' => 'Brüt mənfəət', 'data' => $rows->map(fn ($r) => round($r->gross_profit, 2))->all()],
            ],
            'columnWidth' => '50%',
        ];
    }
}
