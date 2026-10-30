<?php

namespace App\Http\Controllers\Reports;

use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Services\OfficialRevenueService;
use App\Services\ProfitLossService;
use App\Support\Fmt;
use App\Support\NameNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends ReportController
{
    public function __invoke(Request $request): View
    {
        $filters = $this->filters($request, 'this_month', 'VALVO');
        $isAdmin = $request->user()->can('accounting');

        $current = SalesSummaryQuery::totals($filters);
        $previous = SalesSummaryQuery::totals(SalesSummaryQuery::previousYear($filters));
        $orders = $this->orderCount($filters);
        $previousOrders = $this->orderCount(SalesSummaryQuery::previousYear($filters));

        // xərc və xalis mənfəət P&L səhifəsi ilə eyni: kassa + rəsmi (bank) xərclər, re-eksport, rəsmi əlavə gəlir
        $pnl = app(ProfitLossService::class);
        $prevFilters = SalesSummaryQuery::previousYear($filters);
        $totals = $isAdmin ? $pnl->totals($filters) : null;
        $prevTotals = $isAdmin ? $pnl->totals($prevFilters) : null;

        $debt = DB::table('customer_balances as b')->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->where('c.type', 'customer')->where('b.balance', '>', 0)
            ->when($filters->managerId, fn ($q) => $q->where('c.manager_id', $filters->managerId))
            ->selectRaw('SUM(b.balance) AS total, COUNT(*) AS cnt')->first();
        $stock = DB::table('products')->selectRaw('SUM(CASE WHEN stock_qty > 0 THEN stock_qty * COALESCE(current_avg_cost, 0) ELSE 0 END) AS value, SUM(CASE WHEN stock_qty > 0 THEN stock_qty ELSE 0 END) AS qty')->first();

        return view('reports.dashboard', [
            'title' => __('İdarə paneli'),
            'filters' => $filters,
            'isAdmin' => $isAdmin,
            'kpi' => [
                'net_sales' => [$current->net_sales, Fmt::delta($current->net_sales, $previous->net_sales)],
                'gross_profit' => [$current->gross_profit, Fmt::delta($current->gross_profit, $previous->gross_profit)],
                'margin' => [$current->margin, $previous->margin],
                'expenses' => $isAdmin ? [$totals['expenses'], Fmt::delta($totals['expenses'], $prevTotals['expenses'])] : [null, null],
                'net_profit' => $isAdmin ? [$totals['net_profit'], Fmt::delta($totals['net_profit'], $prevTotals['net_profit'])] : null,
                'debt' => [(float) ($debt->total ?? 0), (int) ($debt->cnt ?? 0)],
                'stock' => [(float) ($stock->value ?? 0), (float) ($stock->qty ?? 0)],
                'orders' => [$orders, Fmt::delta($orders, $previousOrders)],
            ],
            'official' => $isAdmin ? $this->official($filters, $pnl) : null,
            'payments' => [$this->payments($filters), $this->payments($prevFilters)],
            'trend' => $this->trend($filters),
            'categories' => $this->categoryShare($filters),
            'topProducts' => $this->topProducts($filters),
            'topDebtors' => $this->topDebtors($filters),
            'managers' => $this->managerSales($filters),
        ]);
    }

    /**
     * Müştərilərdən daxil olan ödənişlər (06 Ödənişlər faylı): bank, nağd və cəmi.
     *
     * @return object{bank: float, cash: float, total: float, count: int}
     */
    private function payments(ReportFilters $filters): object
    {
        $row = DB::table('payments')->whereBetween('date', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->managerId, fn ($q) => $q->where('manager_id', $filters->managerId))
            ->when($filters->customerId, fn ($q) => $q->where('customer_id', $filters->customerId))
            ->selectRaw('SUM(bank) AS bank, SUM(cash) AS cash, SUM(total) AS total, COUNT(*) AS n')->first();

        return (object) ['bank' => (float) ($row->bank ?? 0), 'cash' => (float) ($row->cash ?? 0), 'total' => (float) ($row->total ?? 0), 'count' => (int) ($row->n ?? 0)];
    }

    /**
     * Mühasibat faylından rəsmi göstəricilər: pul vəsaitləri, müştəri daxilolmaları, rəsmi xərclər, re-eksport.
     * Seçilmiş dövr üçün məlumat yoxdursa son mövcud ay göstərilir.
     *
     * @return array<string, mixed>|null
     */
    private function official(ReportFilters $filters, ProfitLossService $pnl): ?array
    {
        $lastPeriod = DB::table('bank_flow_lines')->whereIn('section', ['income', 'payment'])->where('amount', '!=', 0)->max('period');
        if ($lastPeriod === null) {
            return null;
        }
        $summary = app(OfficialRevenueService::class)->cashSummary($filters);
        $lastClosing = (float) DB::table('bank_flow_lines')->where('section', 'closing')->where('period', $lastPeriod)->sum('amount');

        // pul vəsaitləri: bank — mühasibat faylındakı hesablar (oradakı «Kassa» xaric), kassa — sistemin kassası (son sayım)
        $period = $summary['closing_period'] ?? $lastPeriod;
        $accounts = DB::table('bank_flow_balances')->where('period', '<=', $period)->orderByDesc('period')->get()->groupBy('account')
            ->filter(fn ($rows) => $rows->first()->period === $period);
        $isCash = fn (string $account) => str_contains(NameNormalizer::normalize($account), 'kassa');
        $bank = (float) $accounts->reject(fn ($rows, $account) => $isCash((string) $account))->sum(fn ($rows) => (float) $rows->first()->amount);
        $accountingCash = (float) $accounts->filter(fn ($rows, $account) => $isCash((string) $account))->sum(fn ($rows) => (float) $rows->first()->amount);
        $cashDate = min($filters->toDate(), now()->toDateString());
        $checkpoint = DB::table('cash_checkpoints')->where('date', '<=', $cashDate)->orderByDesc('date')->orderByDesc('id')->first();
        $computedCash = (float) DB::table('cash_transactions')->whereNull('finance_document_id')->where('date', '<=', $checkpoint->date ?? $cashDate)->sum(DB::raw('income - expense'));

        return [
            'summary' => $summary,
            'last_period' => $lastPeriod,
            'last_closing' => $lastClosing,
            'bank_balance' => $bank,
            'bank_period' => $period,
            'accounting_cash' => $accountingCash,
            'cash_balance' => $checkpoint ? (float) $checkpoint->balance : $computedCash,
            'cash_date' => $checkpoint->date ?? $cashDate,
            'cash_counted' => $checkpoint !== null,
            'cash_computed' => $computedCash,
            'bank_expenses' => $pnl->bankExpenseTotal($filters),
            'export' => $pnl->exportResultTotal($filters),
        ];
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
                ['name' => __('Xalis satış'), 'type' => 'column', 'data' => $sales],
                ['name' => __('Brüt mənfəət'), 'type' => 'column', 'data' => $profit],
                ['name' => __('Marja %'), 'type' => 'line', 'data' => $margin],
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
            'labels' => $rows->map(fn ($r) => $r->name ?? __('Təyin edilməyib'))->all(),
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
            'series' => [['name' => __('Xalis satış'), 'data' => $rows->map(fn ($r) => round($r->net_sales, 2))->all()]],
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
            'series' => [['name' => __('Borc'), 'data' => $rows->map(fn ($r) => round((float) $r->balance, 2))->all()]],
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
                ['name' => __('Xalis satış'), 'data' => $rows->map(fn ($r) => round($r->net_sales, 2))->all()],
                ['name' => __('Brüt mənfəət'), 'data' => $rows->map(fn ($r) => round($r->gross_profit, 2))->all()],
            ],
            'columnWidth' => '50%',
        ];
    }
}
