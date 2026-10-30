<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Services\BatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Yığım vərəqi (çap): açıq sifarişlərin malları anbar üzrə cəmlənir, hər mal üçün sifariş bölgüsü
 * və FEFO ilə ilk götürüləcək partiyalar (son istifadə tarixi ən yaxın olan) göstərilir.
 */
class PickingListController extends Controller
{
    public function __invoke(Request $request, BatchService $batches): View
    {
        $data = $request->validate(['orders' => ['nullable', 'array'], 'orders.*' => ['integer'], 'warehouse' => ['nullable', 'integer', 'exists:warehouses,id']]);
        $manager = $request->user()->restrictedManagerId();
        $orders = DB::table('sales_orders as o')->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')->where('o.status', 'open')
            ->when(! empty($data['orders']), fn ($q) => $q->whereIn('o.id', $data['orders']))
            ->when(! empty($data['warehouse']), fn ($q) => $q->where('o.warehouse_id', $data['warehouse']))
            ->when($manager !== null, fn ($q) => $q->where('o.manager_id', $manager))
            ->orderBy('o.date')->orderBy('o.id')->get(['o.id', 'o.order_no', 'o.date', 'o.warehouse_id', 'c.name as customer_name']);
        $items = DB::table('sales_order_items as i')->leftJoin('products as p', 'p.id', '=', 'i.product_id')->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->whereIn('i.sales_order_id', $orders->pluck('id')->all() ?: [0])->whereNotNull('i.product_id')
            ->get(['i.sales_order_id', 'i.product_id', 'i.qty', 'i.unit_name', 'i.unit_qty', 'p.code', 'p.name', 'u.name as unit']);
        $orderNo = $orders->pluck('order_no', 'id');
        $fefo = $batches->batches()->where('remaining', '>', 0)->where('source', 'registered')->sortBy('expiry_date')->groupBy('product_id');

        $rows = $items->groupBy('product_id')->map(fn ($g) => (object) [
            'code' => $g->first()->code, 'name' => $g->first()->name, 'unit' => $g->first()->unit, 'qty' => round($g->sum('qty'), 4),
            'split' => $g->map(fn ($i) => $orderNo[$i->sales_order_id].': '.rtrim(rtrim(number_format((float) ($i->unit_qty ?? $i->qty), 2, ',', ' '), '0'), ',').' '.($i->unit_name ?? $i->unit))->implode(' · '),
            'batches' => ($fefo[$g->first()->product_id] ?? collect())->take(2)->map(fn ($b) => $b->batch_no.($b->expiry_date ? ' ('.date('d.m.Y', strtotime($b->expiry_date)).')' : ''))->implode(', '),
        ])->sortBy('code')->values();
        $warehouseIds = $orders->pluck('warehouse_id')->unique()->filter();

        return view('orders.picking', ['title' => __('Yığım vərəqi'), 'orders' => $orders, 'rows' => $rows,
            'warehouses' => Warehouse::query()->whereIn('id', $warehouseIds)->pluck('name')->implode(', ')]);
    }
}
