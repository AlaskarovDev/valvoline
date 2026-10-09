<?php

namespace App\Http\Controllers\Reports;

use App\Models\SalesOrder;
use App\Reports\Table;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends ReportController
{
    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_month');
        $query = DB::table('sales_orders as o')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('managers as m', 'm.id', '=', 'o.manager_id')
            ->whereBetween('o.date', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->managerId, fn ($q) => $q->where('o.manager_id', $filters->managerId))
            ->when($filters->customerId, fn ($q) => $q->where('o.customer_id', $filters->customerId))
            ->when($filters->productId || $filters->groupId || $filters->categoryId || $filters->series, function ($q) use ($filters) {
                $q->whereExists(function ($sub) use ($filters) {
                    $sub->selectRaw('1')->from('sales_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
                        ->whereColumn('l.sales_order_id', 'o.id')
                        ->when($filters->productId, fn ($s) => $s->where('l.product_id', $filters->productId))
                        ->when($filters->groupId, fn ($s) => $s->where('p.product_group_id', $filters->groupId))
                        ->when($filters->categoryId, fn ($s) => $s->where('p.category_id', $filters->categoryId))
                        ->when($filters->series, fn ($s) => $s->where('p.series', $filters->series));
                });
            })
            ->when($request->query('only') === 'loss', fn ($q) => $q->where('o.gross_profit', '<', 0))
            ->select('o.id', 'o.date', 'o.order_no', 'o.invoice_no', 'o.line_count', 'o.total_amount', 'o.total_cogs', 'o.gross_profit',
                'o.return_amount', 'o.net_profit', 'o.customer_id', DB::raw('COALESCE(c.name, o.customer_name) AS customer_name'), 'm.name as manager_name',
                DB::raw('CASE WHEN o.total_amount <> 0 THEN o.gross_profit / o.total_amount ELSE NULL END AS margin'));

        $table = Table::make('orders', 'Sifarişlər')
            ->column('date', 'Tarix', 'date', ['sql' => 'o.date'])
            ->column('order_no', 'Sifariş №', 'text', ['sql' => 'o.order_no', 'link' => fn ($r) => route('orders.show', $r->id)])
            ->column('customer_name', 'Müştəri', 'text', ['sql' => 'COALESCE(c.name, o.customer_name)', 'link' => fn ($r) => $r->customer_id ? route('customers.show', $r->customer_id) : null])
            ->column('manager_name', 'Menecer', 'text', ['sql' => 'm.name'])
            ->column('line_count', 'Sətir', 'int', ['sql' => 'o.line_count', 'mobile' => false])
            ->column('total_amount', 'Məbləğ', 'money', ['sql' => 'o.total_amount'])
            ->column('total_cogs', 'Maya', 'money', ['sql' => 'o.total_cogs', 'mobile' => false])
            ->column('gross_profit', 'Qazanc', 'money', ['sql' => 'o.gross_profit', 'class' => fn ($v) => $v < 0 ? 'neg' : ''])
            ->column('margin', 'Marja', 'pct', ['sql' => 'CASE WHEN o.total_amount <> 0 THEN o.gross_profit / o.total_amount ELSE NULL END', 'class' => fn ($v) => $v !== null && $v < 0 ? 'neg' : ''])
            ->column('return_amount', 'Qaytarma', 'money', ['sql' => 'o.return_amount', 'mobile' => false])
            ->column('net_profit', 'Xalis qazanc', 'money', ['sql' => 'o.net_profit', 'class' => fn ($v) => $v < 0 ? 'neg font-semibold' : 'font-semibold'])
            ->source($query)
            ->sortBy('date', 'desc')
            ->rowClass(fn ($r) => $r->gross_profit < 0 ? 'row-neg' : '')
            ->footer(auto: true)
            ->subtitle('Qırmızı sətirlər — zərərli sifarişlər. «Xalis qazanc» sonrakı qaytarmalar çıxılmaqla.');

        if ($response = $this->exportIfRequested($request, [$table], 'Sifarişlər və qazanc', $filters)) {
            return $response;
        }
        $rows = $table->resolve($request);
        $loss = (clone $query)->where('o.gross_profit', '<', 0)->count();

        return view('reports.orders', ['title' => 'Sifarişlər və qazanc', 'filters' => $filters, 'table' => $table, 'rows' => $rows, 'lossCount' => $loss]);
    }

    public function show(Request $request, SalesOrder $order): View|Response
    {
        $user = $request->user();
        abort_if(! $user->isAdmin() && $order->manager_id !== $user->manager_id, 403);

        $lines = DB::table('sales_lines as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.sales_order_id', $order->id)
            ->select('l.*', 'p.name as pname', 'p.cost_estimated')
            ->orderBy('l.row_no');
        $table = Table::make('lines', 'Mal sətirləri')
            ->column('product_code', 'Kod', 'text', ['link' => fn ($r) => $r->product_id ? route('movements.product', $r->product_id) : null])
            ->column('product_name', 'Mal', 'text', ['format' => fn ($v, $r) => e($r->pname ?? $v).($r->cost_estimated ? ' <span class="warn" title="Maya təxminidir">⚠</span>' : '')])
            ->column('op_type', 'Tip', 'text', ['format' => fn ($v) => e(['sale' => 'Satış', 'gift' => 'Hədiyyə / pulsuz', 'internal' => 'Daxili istifadə'][$v] ?? $v)])
            ->column('qty', 'Miqdar', 'qty')
            ->column('price', 'Qiymət', 'money')
            ->column('discount_pct', 'Endirim', 'pct')
            ->column('net_amount', 'Yekun', 'money')
            ->column('unit_cost', 'Vahid maya', 'money')
            ->column('cogs', 'Maya', 'money')
            ->column('gross_profit', 'Qazanc', 'money', ['class' => fn ($v) => $v < 0 ? 'neg' : ''])
            ->column('margin_pct', 'Marja', 'pct')
            ->source($lines)->perPage(200)->footer(auto: true)->withoutSearch()
            ->rowClass(fn ($r) => $r->gross_profit !== null && $r->gross_profit < 0 ? 'row-neg' : '');

        $returns = DB::table('sales_returns')->where('sales_order_id', $order->id)->orderBy('date');
        $returnTable = Table::make('returns', 'Bu sifarişə aid qaytarmalar')
            ->column('date', 'Tarix', 'date')
            ->column('return_no', 'Qaytarma №')
            ->column('product_code', 'Kod')
            ->column('product_name', 'Mal')
            ->column('qty', 'Miqdar', 'qty')
            ->column('net_amount', 'Məbləğ', 'money')
            ->column('cogs', 'Maya', 'money')
            ->column('reason', 'Səbəb')
            ->source($returns)->perPage(100)->footer(auto: true)->withoutSearch();

        if ($response = $this->exportIfRequested($request, [$table, $returnTable], 'Sifariş '.$order->order_no)) {
            return $response;
        }

        return view('reports.order', [
            'title' => 'Sifariş № '.$order->order_no,
            'order' => $order->load('customer', 'manager'),
            'table' => $table,
            'rows' => $table->resolve($request),
            'returnTable' => $returnTable,
            'returnRows' => $returnTable->resolve($request),
            'margin' => $order->total_amount != 0 ? $order->gross_profit / $order->total_amount : null,
            'fmt' => Fmt::class,
        ]);
    }
}
