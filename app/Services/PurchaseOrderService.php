<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Reports\ReportFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Alış sifarişləri və satınalma təklifi.
 *
 * Təklif (mal üzrə): sərbəst qalıq = real stok − rezerv + yoldakı sifariş.
 *   ehtiyat = min stok (yoxdursa SAFETY_DAYS günlük satış); sifariş nöqtəsi = gündəlik satış × çatdırılma günü + ehtiyat;
 *   sərbəst qalıq sifariş nöqtəsindən aşağıdırsa: təklif = gündəlik satış × (çatdırılma + örtük günü) + ehtiyat − sərbəst qalıq
 *   (maksimum stok verilibsə, ondan yuxarı qaldırılmır).
 */
class PurchaseOrderService
{
    public const DEFAULT_LEAD_DAYS = 30;

    public const SAFETY_DAYS = 14;

    public const DEFAULT_COVER_DAYS = 30;

    /**
     * @return Collection<int, object>
     */
    public function suggestions(?Supplier $supplier, int $coverDays, bool $all): Collection
    {
        $lead = (int) ($supplier?->lead_days ?? self::DEFAULT_LEAD_DAYS);
        $stock = app(StockService::class)->balances(new ReportFilters(now()->startOfYear(), now()->endOfDay()))->keyBy('id');
        $reserved = collect(app(SalesDocumentService::class)->reserved())->map(fn ($w) => array_sum($w));
        $onOrder = $this->onOrder();
        // malın son təchizatçısı və onun son qiyməti (təchizatçı valyutasında)
        $last = DB::table('purchase_document_lines as l')->join('purchase_documents as d', 'd.id', '=', 'l.purchase_document_id')
            ->whereNull('d.cancelled_at')->where('d.type', 'receipt')->orderBy('d.date')->orderBy('l.id')
            ->get(['l.product_id', 'd.supplier_id', 'l.price'])->keyBy('product_id');

        $products = DB::table('products as p')->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')->where('p.is_active', true)
            ->when($supplier && ! $all, fn ($q) => $q->whereIn('p.id', $last->where('supplier_id', $supplier->id)->keys()->all() ?: [0]))
            ->orderBy('p.name')->get(['p.id', 'p.code', 'p.name', 'p.min_stock_qty', 'p.max_stock_qty', 'u.name as unit']);

        return $products->map(function ($p) use ($stock, $reserved, $onOrder, $last, $lead, $coverDays, $supplier) {
            $qty = (float) ($stock[$p->id]->qty ?? 0);
            $daily = (float) ($stock[$p->id]->daily_sales ?? 0);
            $available = $qty - (float) ($reserved[$p->id] ?? 0) + (float) ($onOrder[$p->id] ?? 0);
            $safety = $p->min_stock_qty !== null ? (float) $p->min_stock_qty : $daily * self::SAFETY_DAYS;
            $reorderPoint = $daily * $lead + $safety;
            $target = $daily * ($lead + $coverDays) + $safety;
            if ($p->max_stock_qty !== null) {
                $target = min($target, (float) $p->max_stock_qty);
            }
            $suggested = $available < $reorderPoint ? max(0.0, ceil($target - $available)) : 0.0;
            $lastRow = $last[$p->id] ?? null;

            return (object) [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'unit' => $p->unit, 'stock' => round($qty, 4), 'reserved' => round((float) ($reserved[$p->id] ?? 0), 4),
                'on_order' => round((float) ($onOrder[$p->id] ?? 0), 4), 'daily' => round($daily, 3), 'days_left' => $daily > 0 ? round(max(0, $available) / $daily) : null,
                'reorder_point' => round($reorderPoint, 2), 'suggested' => $suggested,
                'price' => $lastRow && (! $supplier || (int) $lastRow->supplier_id === $supplier->id) ? (float) $lastRow->price : null,
            ];
        })->filter(fn ($r) => $all || $r->suggested > 0)->values();
    }

    /**
     * Açıq sifarişlərdə gözlənilən (hələ qəbul edilməmiş) miqdar: [product_id => qty].
     *
     * @return array<int, float>
     */
    public function onOrder(): array
    {
        $out = [];
        $orders = PurchaseOrder::query()->whereNull('cancelled_at')->whereNull('closed_at')->pluck('id');
        foreach ($this->progress($orders->all()) as $lines) {
            foreach ($lines as $productId => $l) {
                $out[$productId] = ($out[$productId] ?? 0.0) + max(0.0, $l['qty'] - $l['received']);
            }
        }

        return $out;
    }

    /**
     * Sifariş sətirləri və qəbul olunan miqdar (aktiv mədaxillərdən): [order_id => [product_id => {qty, received}]].
     *
     * @param  list<int>  $orderIds
     * @return array<int, array<int, array{qty: float, received: float}>>
     */
    public function progress(array $orderIds): array
    {
        if (! $orderIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('purchase_order_lines')->whereIn('purchase_order_id', $orderIds)->get(['purchase_order_id', 'product_id', 'qty']) as $l) {
            $out[(int) $l->purchase_order_id][(int) $l->product_id] ??= ['qty' => 0.0, 'received' => 0.0];
            $out[(int) $l->purchase_order_id][(int) $l->product_id]['qty'] += (float) $l->qty;
        }
        $received = DB::table('purchase_document_lines as l')->join('purchase_documents as d', 'd.id', '=', 'l.purchase_document_id')
            ->whereNull('d.cancelled_at')->where('d.type', 'receipt')->whereIn('d.purchase_order_id', $orderIds)
            ->groupBy('d.purchase_order_id', 'l.product_id')->selectRaw('d.purchase_order_id, l.product_id, SUM(l.qty) AS qty')->get();
        foreach ($received as $r) {
            if (isset($out[(int) $r->purchase_order_id][(int) $r->product_id])) {
                $out[(int) $r->purchase_order_id][(int) $r->product_id]['received'] += (float) $r->qty;
            }
        }

        return $out;
    }

    public function status(PurchaseOrder $order, ?array $lines = null): string
    {
        if ($order->cancelled_at) {
            return 'cancelled';
        }
        $lines ??= $this->progress([$order->id])[$order->id] ?? [];
        $received = array_sum(array_column($lines, 'received'));
        $complete = $lines && collect($lines)->every(fn ($l) => $l['qty'] <= $l['received'] + 1e-6);

        return $complete ? 'received' : ($order->closed_at ? 'closed' : ($received > 0 ? 'partial' : 'open'));
    }

    /**
     * @param  array{date: string, supplier_id: int, warehouse_id: int, workplace_id?: int|null, expected_date?: string|null, note?: string|null}  $header
     * @param  list<array{product_id: int, qty: float|string, price?: float|string|null}>  $lines
     */
    public function create(array $header, array $lines, ?int $userId): PurchaseOrder
    {
        $lines = array_values(array_filter($lines, fn ($l) => (float) $l['qty'] > 0));
        if (! $lines) {
            throw new RuntimeException(__('Sifarişdə miqdarı olan mal yoxdur.'));
        }
        $supplier = Supplier::query()->findOrFail($header['supplier_id']);
        $date = Carbon::parse($header['date']);
        $products = DB::table('products')->whereIn('id', array_column($lines, 'product_id'))->get(['id', 'code', 'name'])->keyBy('id');

        return DB::transaction(function () use ($header, $lines, $supplier, $date, $products, $userId) {
            $order = PurchaseOrder::query()->create([
                'doc_no' => app(NumberingService::class)->next('purchase_order', ['date' => $date, 'warehouse_id' => $header['warehouse_id'], 'workplace_id' => $header['workplace_id'] ?? null]),
                'date' => $date->toDateString(), 'supplier_id' => $supplier->id, 'warehouse_id' => $header['warehouse_id'], 'workplace_id' => $header['workplace_id'] ?? null,
                'currency' => $supplier->currency ?: 'AZN', 'expected_date' => $header['expected_date'] ?? $date->copy()->addDays((int) ($supplier->lead_days ?? self::DEFAULT_LEAD_DAYS))->toDateString(),
                'note' => $header['note'] ?? null, 'created_by' => $userId,
            ]);
            $total = 0.0;
            foreach ($lines as $i => $l) {
                $p = $products[(int) $l['product_id']];
                $amount = round((float) $l['qty'] * (float) ($l['price'] ?? 0), 4);
                $total += $amount;
                DB::table('purchase_order_lines')->insert(['purchase_order_id' => $order->id, 'row_no' => $i + 1, 'product_id' => $p->id, 'product_code' => $p->code,
                    'product_name' => $p->name, 'qty' => round((float) $l['qty'], 4), 'price' => round((float) ($l['price'] ?? 0), 6), 'amount' => $amount,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $order->forceFill(['total_amount' => round($total, 4)])->save();

            return $order;
        });
    }

    /**
     * Mədaxil qaiməsi forması üçün: sifarişin hələ qəbul olunmamış qalığı.
     *
     * @return list<array{product_id: int, qty: float, price: float}>
     */
    public function remainingLines(PurchaseOrder $order): array
    {
        $progress = $this->progress([$order->id])[$order->id] ?? [];

        return DB::table('purchase_order_lines')->where('purchase_order_id', $order->id)->orderBy('row_no')->get(['product_id', 'qty', 'price'])
            ->unique('product_id')
            ->map(fn ($l) => ['product_id' => (int) $l->product_id, 'qty' => round(max(0.0, ($progress[$l->product_id]['qty'] ?? 0) - ($progress[$l->product_id]['received'] ?? 0)), 4), 'price' => (float) $l->price])
            ->filter(fn ($l) => $l['qty'] > 0)->values()->all();
    }
}
