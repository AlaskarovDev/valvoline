<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Jobs\RebuildAnalytics;
use App\Models\Customer;
use App\Models\Manager;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Sistemdə sifariş yaratmaq / düzəltmək (əl ilə daxil edilən sifarişlər).
 * Maya (FIFO), anbar qalığı və sifariş cəmləri yadda saxlandıqdan sonra arxa planda yenidən hesablanır.
 */
class OrderEntryController extends Controller
{
    public function create(Request $request): View
    {
        return $this->form($request, null);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $order = DB::transaction(fn () => $this->save($request, new SalesOrder(['source' => 'manual', 'created_by' => $request->user()->id])));
        $this->rebuild();

        return redirect()->route('orders.show', $order)->with('status', __('Sifariş əlavə olundu. Maya dəyəri bir neçə saniyəyə hesablanacaq.'));
    }

    public function edit(Request $request, SalesOrder $order): View
    {
        $this->authorizeManual($request, $order);

        return $this->form($request, $order);
    }

    public function update(StoreOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        $this->authorizeManual($request, $order);
        DB::transaction(fn () => $this->save($request, $order));
        $this->rebuild();

        return redirect()->route('orders.show', $order)->with('status', __('Sifariş yeniləndi.'));
    }

    public function destroy(Request $request, SalesOrder $order): RedirectResponse
    {
        $this->authorizeManual($request, $order);
        DB::transaction(function () use ($order) {
            DB::table('sales_lines')->where('sales_order_id', $order->id)->delete();
            $order->delete();
        });
        $this->rebuild();

        return redirect()->route('orders.index')->with('status', __('Sifariş silindi.'));
    }

    /**
     * Növbəti sifariş nömrəsi: mövcud rəqəmsal nömrələrin ən böyüyü + 1.
     */
    public static function nextOrderNo(): string
    {
        $max = DB::table('sales_orders')->pluck('order_no')
            ->map(fn ($no) => ctype_digit((string) $no) ? (int) $no : 0)->max() ?? 0;

        return (string) ($max + 1);
    }

    private function form(Request $request, ?SalesOrder $order): View
    {
        $restricted = $request->user()->restrictedManagerId();
        $customers = Customer::query()
            ->when($restricted !== null, fn ($q) => $q->where('manager_id', $restricted))
            ->orderBy('name')->get(['id', 'code', 'name', 'manager_id', 'voen'])
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'manager_id' => $c->manager_id, 'voen' => $c->voen])->values();
        $stock = DB::table('stock_balances')->get(['product_id', 'warehouse_id', 'qty'])
            ->groupBy('product_id')->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->warehouse_id => round((float) $r->qty, 2)]));
        $products = DB::table('products')->orderBy('name')->get(['id', 'code', 'name', 'sale_price'])
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'price' => round((float) $p->sale_price, 2), 'stock' => $stock[$p->id] ?? (object) []])->values();
        $warehouses = Warehouse::query()->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'is_main']);

        $lines = $order
            ? DB::table('sales_lines')->where('sales_order_id', $order->id)->orderBy('row_no')->get()
                ->map(fn ($l) => ['product_id' => $l->product_id, 'op_type' => $l->op_type, 'qty' => (float) $l->qty, 'price' => (float) $l->price, 'discount' => round((float) $l->discount_pct * 100, 2)])->all()
            : [];

        return view('orders.form', [
            'title' => $order ? __('Sifariş № ').$order->order_no.' — '.__('düzəliş') : __('Yeni sifariş'),
            'order' => $order,
            'values' => [
                'date' => old('date', $order?->date?->toDateString() ?? now()->toDateString()),
                'order_no' => old('order_no', $order->order_no ?? self::nextOrderNo()),
                'invoice_no' => old('invoice_no', $order->invoice_no ?? ''),
                'customer_id' => (int) old('customer_id', $order->customer_id ?? 0) ?: null,
                'manager_id' => (int) old('manager_id', $order->manager_id ?? ($restricted ?? 0)) ?: null,
                'warehouse_id' => (int) old('warehouse_id', $order->warehouse_id ?? $warehouses->firstWhere('is_main', true)?->id ?? $warehouses->first()?->id),
                'note' => old('note', $order->note ?? ''),
                'lines' => old('lines', $lines),
            ],
            'customers' => $customers,
            'products' => $products,
            'warehouses' => $warehouses,
            'managers' => Manager::query()->when($restricted !== null, fn ($q) => $q->whereKey($restricted))->orderBy('name')->get(['id', 'name']),
            'managerLocked' => $restricted !== null,
        ]);
    }

    private function save(StoreOrderRequest $request, SalesOrder $order): SalesOrder
    {
        $data = $request->validated();
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $restricted = $request->user()->restrictedManagerId();
        $managerId = $restricted ?? ($data['manager_id'] ?? $customer->manager_id);
        $managerName = $managerId ? Manager::query()->whereKey($managerId)->value('name') : null;
        $date = Carbon::parse($data['date']);
        $products = DB::table('products')->whereIn('id', array_column($data['lines'], 'product_id'))->get(['id', 'code', 'name'])->keyBy('id');

        $order->fill([
            'order_no' => $data['order_no'],
            'invoice_no' => $data['invoice_no'] ?? null,
            'date' => $date->toDateString(),
            'period' => $date->format('Y-m'),
            'customer_id' => $customer->id,
            'customer_code' => $customer->code,
            'customer_name' => $customer->name,
            'manager_id' => $managerId,
            'warehouse_id' => $data['warehouse_id'],
            'note' => $data['note'] ?? null,
        ])->save();

        DB::table('sales_lines')->where('sales_order_id', $order->id)->delete();
        $now = now();
        $rows = [];
        $total = 0.0;
        foreach (array_values($data['lines']) as $i => $line) {
            $product = $products[$line['product_id']];
            $qty = (float) $line['qty'];
            $price = (float) $line['price'];
            $discount = (float) ($line['discount'] ?? 0) / 100;
            $amount = round($qty * $price, 4);
            $net = round($amount * (1 - $discount), 4);
            if ($line['op_type'] === 'sale') {
                $total += $net;
            }
            $rows[] = [
                'sales_order_id' => $order->id, 'row_no' => $i + 1,
                'date' => $order->date->toDateString(), 'period' => $order->period,
                'order_no' => $order->order_no, 'invoice_no' => $order->invoice_no,
                'manager_id' => $managerId, 'manager_name' => $managerName, 'warehouse_id' => $order->warehouse_id,
                'customer_id' => $customer->id, 'customer_code' => $customer->code, 'customer_name' => $customer->name, 'original_customer_name' => $customer->name,
                'product_id' => $product->id, 'product_code' => $product->code, 'product_name' => $product->name,
                'qty' => $qty, 'price' => $price, 'amount' => $amount, 'discount_pct' => $discount,
                'discount_price' => round($price * (1 - $discount), 4), 'net_amount' => $net,
                'op_type' => $line['op_type'], 'status' => 'ok',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('sales_lines')->insert($rows);
        // maya arxa planda hesablanana qədər siyahıda məbləğ dərhal görünsün
        $order->forceFill(['line_count' => count($rows), 'total_amount' => round($total, 4)])->save();

        return $order;
    }

    private function authorizeManual(Request $request, SalesOrder $order): void
    {
        abort_unless($order->source === 'manual', 403, __('İmportdan gələn sifariş burada dəyişdirilmir — faylı yenidən import edin.'));
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $order->manager_id !== $restricted, 403);
    }

    private function rebuild(): void
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }
}
