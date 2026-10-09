<?php

namespace App\Http\Controllers\Reports;

use App\Reports\SalesSummaryQuery;
use App\Reports\Table;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReturnController extends ReportController
{
    public function __invoke(Request $request): View|Response
    {
        $filters = $this->filters($request);
        $totals = SalesSummaryQuery::totals($filters);

        $base = fn () => DB::table('sales_returns as r')
            ->leftJoin('products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'r.customer_id')
            ->where('r.op_type', 'customer')
            ->whereBetween('r.date', [$filters->fromDate(), $filters->toDate()])
            ->tap(fn ($q) => SalesSummaryQuery::applyDimensions($q, $filters, 'r', 'p'));

        // aylıq trend: qaytarma məbləği + qaytarma faizi
        $monthly = SalesSummaryQuery::base($filters)->groupBy('s.period')->selectRaw('s.period, '.SalesSummaryQuery::METRICS)->get()
            ->map(fn ($r) => SalesSummaryQuery::derive($r))->sortBy('period')->values();
        $trend = [
            'type' => 'line',
            'categories' => $monthly->map(fn ($r) => Fmt::period($r->period))->all(),
            'series' => [
                ['name' => 'Qaytarma', 'type' => 'column', 'data' => $monthly->map(fn ($r) => round($r->returns, 2))->all()],
                ['name' => 'Qaytarma faizi', 'type' => 'line', 'data' => $monthly->map(fn ($r) => $r->return_rate !== null ? round($r->return_rate, 4) : null)->all()],
            ],
            'colors' => ['--chart-6', '--chart-1'],
            'dualAxis' => true,
            'secondFormat' => 'pct',
            'strokeWidths' => [0, 2.5],
            'markers' => true,
        ];

        $reasons = $base()->groupBy('r.reason')->selectRaw("COALESCE(r.reason, 'Göstərilməyib') AS reason, SUM(r.net_amount) AS amount, COUNT(*) AS cnt")
            ->orderByDesc('amount')->get();
        $reasonChart = [
            'type' => 'donut',
            'labels' => $reasons->pluck('reason')->all(),
            'series' => $reasons->map(fn ($r) => round((float) $r->amount, 2))->all(),
        ];

        $salesByProduct = SalesSummaryQuery::base($filters)->groupBy('s.product_id')->selectRaw("s.product_id, SUM(CASE WHEN s.kind = 'sale' THEN s.net_amount ELSE 0 END) AS sales")->pluck('sales', 'product_id');
        $products = $base()->groupBy('r.product_id', 'r.product_code', 'p.name')
            ->selectRaw('r.product_id, r.product_code AS code, COALESCE(p.name, r.product_code) AS name, SUM(r.qty) AS qty, SUM(r.net_amount) AS amount, SUM(r.cogs) AS cogs, COUNT(*) AS cnt')
            ->get()->map(function ($r) use ($salesByProduct) {
                $sales = (float) ($salesByProduct[$r->product_id] ?? 0);
                $r->rate = $sales > 0 ? (float) $r->amount / $sales : null;

                return $r;
            });
        $productTable = Table::make('rp', 'Ən çox qaytarılan mallar')
            ->column('code', 'Kod', 'text', ['link' => fn ($r) => $r->product_id ? route('movements.product', $r->product_id) : null])
            ->column('name', 'Mal')
            ->column('cnt', 'Sətir', 'int')
            ->column('qty', 'Miqdar', 'qty')
            ->column('amount', 'Məbləğ', 'money')
            ->column('cogs', 'Maya', 'money')
            ->column('rate', 'Satışa nisbəti', 'pct')
            ->source($products)->sortBy('amount')->perPage(15)->footer(auto: true);

        $salesByCustomer = SalesSummaryQuery::base($filters)->groupBy('s.customer_id')->selectRaw("s.customer_id, SUM(CASE WHEN s.kind = 'sale' THEN s.net_amount ELSE 0 END) AS sales")->pluck('sales', 'customer_id');
        $customers = $base()->groupBy('r.customer_id', 'c.name', 'r.customer_name')
            ->selectRaw('r.customer_id, COALESCE(c.name, r.customer_name) AS name, SUM(r.net_amount) AS amount, COUNT(DISTINCT r.return_no) AS docs, COUNT(*) AS cnt')
            ->get()->map(function ($r) use ($salesByCustomer) {
                $sales = (float) ($salesByCustomer[$r->customer_id] ?? 0);
                $r->sales = $sales;
                $r->rate = $sales > 0 ? (float) $r->amount / $sales : null;

                return $r;
            });
        $customerTable = Table::make('rc', 'Ən çox qaytaran müştərilər')
            ->column('name', 'Müştəri', 'text', ['link' => fn ($r) => $r->customer_id ? route('customers.show', $r->customer_id) : null])
            ->column('docs', 'Sənəd', 'int')
            ->column('amount', 'Qaytarma', 'money')
            ->column('sales', 'Satış', 'money')
            ->column('rate', 'Qaytarma faizi', 'pct', ['class' => fn ($v) => $v !== null && $v > 0.2 ? 'neg font-semibold' : ''])
            ->source($customers)->sortBy('amount')->perPage(15)->footer(auto: true);

        $listTable = Table::make('rl', 'Qaytarma sətirləri')
            ->column('date', 'Tarix', 'date', ['sql' => 'r.date'])
            ->column('return_no', 'Qaytarma №', 'text', ['sql' => 'r.return_no'])
            ->column('customer', 'Müştəri', 'text', ['sql' => 'COALESCE(c.name, r.customer_name)', 'link' => fn ($r) => $r->customer_id ? route('customers.show', $r->customer_id) : null])
            ->column('product_code', 'Kod', 'text', ['sql' => 'r.product_code'])
            ->column('product_name', 'Mal', 'text', ['sql' => 'COALESCE(p.name, r.product_name)'])
            ->column('qty', 'Miqdar', 'qty', ['sql' => 'r.qty'])
            ->column('net_amount', 'Məbləğ', 'money', ['sql' => 'r.net_amount'])
            ->column('cogs', 'Maya', 'money', ['sql' => 'r.cogs'])
            ->column('reason', 'Səbəb', 'text', ['sql' => 'r.reason'])
            ->column('order_no', 'Sifariş', 'text', ['sql' => 'o.order_no', 'link' => fn ($r) => $r->sales_order_id ? route('orders.show', $r->sales_order_id) : null])
            ->source($base()->leftJoin('sales_orders as o', 'o.id', '=', 'r.sales_order_id')
                ->select('r.date', 'r.return_no', 'r.customer_id', 'r.product_code', 'r.qty', 'r.net_amount', 'r.cogs', 'r.reason', 'r.sales_order_id', 'o.order_no',
                    DB::raw('COALESCE(c.name, r.customer_name) AS customer'), DB::raw('COALESCE(p.name, r.product_name) AS product_name')))
            ->sortBy('date')->perPage(50)->footer(auto: true);

        if ($response = $this->exportIfRequested($request, [$productTable, $customerTable, $listTable], 'Qaytarmalar', $filters)) {
            return $response;
        }

        return view('reports.returns', [
            'title' => 'Qaytarmalar',
            'filters' => $filters,
            'totals' => $totals,
            'trend' => $trend,
            'reasonChart' => $reasonChart,
            'productTable' => $productTable, 'productRows' => $productTable->resolve($request),
            'customerTable' => $customerTable, 'customerRows' => $customerTable->resolve($request),
            'listTable' => $listTable, 'listRows' => $listTable->resolve($request),
        ]);
    }
}
