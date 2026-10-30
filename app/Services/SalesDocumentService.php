<?php

namespace App\Services;

use App\Models\DeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Satış sənədləri axını.
 *  Sifariş (open)      — yalnız rezerv: sərbəst (rezerv) stokdan çıxır, real stoka və borca təsir etmir.
 *  Təhvil-təslim (TT)  — real stoku azaldır, rezerv azad olunur (status: shipped). Maya FIFO ilə TT sətrində hesablanır.
 *  Satış fakturası (SF) — satış sətirləri yaradır: gəlir + müştəri borcu (status: invoiced). Stok TT-dən çıxdığı üçün təkrar azalmır.
 */
class SalesDocumentService
{
    public const DELIVERY_PREFIX = 'TT-';

    public const INVOICE_PREFIX = 'SF-';

    /**
     * Açıq sifarişlərdə rezerv olunmuş miqdar: [product_id][warehouse_id] => qty.
     *
     * @return array<int, array<int, float>>
     */
    public function reserved(?int $exceptOrderId = null): array
    {
        $out = [];
        $rows = DB::table('sales_order_items as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->where('o.status', 'open')->whereNotNull('i.product_id')
            ->when($exceptOrderId, fn ($q) => $q->where('o.id', '<>', $exceptOrderId))
            ->groupBy('i.product_id', 'o.warehouse_id')
            ->selectRaw('i.product_id, o.warehouse_id, SUM(i.qty) AS qty')->get();
        foreach ($rows as $r) {
            $out[(int) $r->product_id][(int) $r->warehouse_id] = (float) $r->qty;
        }

        return $out;
    }

    /**
     * «Sifarişi göndər»: sifarişin bütün sətirlərindən təhvil-təslim sənədi.
     */
    public function deliver(SalesOrder $order, ?int $userId, ?Carbon $date = null): DeliveryNote
    {
        $order->assertNotCancelled();
        if ($order->status !== 'open') {
            throw new RuntimeException(__('Sifariş artıq təhvil verilib.'));
        }

        return DB::transaction(function () use ($order, $userId, $date) {
            $date ??= Carbon::parse($order->date)->max(now()->startOfDay());
            PeriodLock::assertOpen($date);
            $items = DB::table('sales_order_items')->where('sales_order_id', $order->id)->orderBy('row_no')->get();
            $note = DeliveryNote::query()->create([
                'doc_no' => app(NumberingService::class)->next('delivery_note', ['date' => $date, 'workplace_id' => $order->workplace_id, 'warehouse_id' => $order->warehouse_id, 'manager_id' => $order->manager_id]),
                'date' => $date->toDateString(), 'period' => $date->format('Y-m'),
                'sales_order_id' => $order->id, 'customer_id' => $order->customer_id, 'customer_code' => $order->customer_code,
                'customer_name' => $order->customer_name, 'manager_id' => $order->manager_id, 'warehouse_id' => $order->warehouse_id,
                'workplace_id' => $order->workplace_id, 'note' => $order->note, 'total_qty' => round($items->sum('qty'), 4),
                'total_amount' => round($items->where('op_type', 'sale')->sum('net_amount'), 4), 'created_by' => $userId,
            ]);
            $now = now();
            foreach ($items as $i => $item) {
                DB::table('delivery_note_lines')->insert([
                    'delivery_note_id' => $note->id, 'sales_order_item_id' => $item->id, 'row_no' => $i + 1,
                    'product_id' => $item->product_id, 'product_code' => $item->product_code, 'product_name' => $item->product_name,
                    'op_type' => $item->op_type, 'qty' => $item->qty, 'price' => $item->price, 'net_amount' => $item->net_amount,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $order->forceFill(['status' => 'shipped'])->save();

            return $note;
        });
    }

    /**
     * «Fakturala»: təhvil-təslim yoxdursa əvvəlcə onu yaradır, sonra satış fakturası.
     *
     * @return array{delivery: DeliveryNote|null, invoice: SalesInvoice}
     */
    public function invoice(SalesOrder $order, ?int $userId, ?Carbon $date = null): array
    {
        $order->assertNotCancelled();
        if ($order->status === 'invoiced') {
            throw new RuntimeException(__('Sifariş artıq fakturalanıb.'));
        }

        return DB::transaction(function () use ($order, $userId, $date) {
            $created = $order->status === 'open' ? $this->deliver($order, $userId, $date) : null;
            $note = $created ?? DeliveryNote::query()->active()->where('sales_order_id', $order->id)->latest('id')->firstOrFail();
            $date ??= Carbon::parse($note->date)->max(now()->startOfDay());
            PeriodLock::assertOpen($date);
            $items = DB::table('sales_order_items')->where('sales_order_id', $order->id)->get()->keyBy('id');
            $lines = DB::table('delivery_note_lines')->where('delivery_note_id', $note->id)->orderBy('row_no')->get();
            $sale = $items->where('op_type', 'sale');

            $invoice = SalesInvoice::query()->create([
                'doc_no' => app(NumberingService::class)->next('sales_invoice', ['date' => $date, 'workplace_id' => $order->workplace_id, 'warehouse_id' => $order->warehouse_id, 'manager_id' => $order->manager_id]),
                'date' => $date->toDateString(), 'period' => $date->format('Y-m'),
                'due_date' => $date->copy()->addDays((int) DB::table('customers')->where('id', $order->customer_id)->value('payment_days'))->toDateString(),
                'sales_order_id' => $order->id, 'delivery_note_id' => $note->id,
                'customer_id' => $order->customer_id, 'customer_code' => $order->customer_code, 'customer_name' => $order->customer_name,
                'manager_id' => $order->manager_id, 'warehouse_id' => $order->warehouse_id, 'workplace_id' => $order->workplace_id, 'note' => $order->note,
                'gross_amount' => round($sale->sum('amount'), 4),
                'discount_amount' => round($sale->sum('amount') - $sale->sum('net_amount'), 4),
                'total_amount' => round($sale->sum('net_amount'), 4), 'created_by' => $userId,
            ]);

            $managerName = $order->manager_id ? DB::table('managers')->where('id', $order->manager_id)->value('name') : null;
            // ƏDV fakturalama anındakı mal parametrləri ilə sətirdə saxlanılır
            $vatInfo = DB::table('products')->whereIn('id', $lines->pluck('product_id')->filter()->all() ?: [0])->get(['id', 'vat_mode', 'vat_rate'])->keyBy('id');
            $vatTotal = 0.0;
            $now = now();
            foreach ($lines as $i => $line) {
                $item = $items[$line->sales_order_item_id] ?? null;
                $qty = (float) $line->qty;
                $p = $vatInfo[$line->product_id] ?? null;
                $vat = Vat::split($line->op_type === 'sale' ? (float) $line->net_amount : 0.0, $p->vat_mode ?? 'standard', $p->vat_rate ?? 18);
                DB::table('sales_lines')->insert([
                    'sales_order_id' => $order->id, 'sales_invoice_id' => $invoice->id, 'delivery_note_line_id' => $line->id,
                    'row_no' => $i + 1, 'date' => $invoice->date->toDateString(), 'period' => $invoice->period,
                    'order_no' => $order->order_no, 'invoice_no' => $invoice->doc_no,
                    'manager_id' => $order->manager_id, 'manager_name' => $managerName, 'warehouse_id' => $order->warehouse_id,
                    'customer_id' => $order->customer_id, 'customer_code' => $order->customer_code, 'customer_name' => $order->customer_name,
                    'original_customer_name' => $order->customer_name,
                    'product_id' => $line->product_id, 'product_code' => $line->product_code, 'product_name' => $line->product_name,
                    'qty' => $qty, 'price' => $line->price, 'amount' => $item->amount ?? 0, 'discount_pct' => $item->discount_pct ?? 0,
                    'campaign_discount' => $item->campaign_discount ?? 0, 'discount_price' => $qty > 0 ? round((float) $line->net_amount / $qty, 4) : 0,
                    'net_amount' => $line->net_amount, 'op_type' => $line->op_type, 'campaign_id' => $item->campaign_id ?? null,
                    'vat_mode' => $vatInfo[$line->product_id]->vat_mode ?? 'standard', 'vat_rate' => $vat['rate'], 'vat_amount' => $vat['vat'],
                    'status' => 'ok', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $vatTotal += $vat['vat'];
            }
            $invoice->forceFill(['vat_amount' => round($vatTotal, 4), 'amount_ex_vat' => round((float) $invoice->total_amount - $vatTotal, 4)])->save();
            $order->forceFill(['status' => 'invoiced', 'invoice_no' => $order->invoice_no ?: $invoice->doc_no])->save();

            return ['delivery' => $created, 'invoice' => $invoice];
        });
    }

    /**
     * Fakturanı ləğv edir: faktura və nömrəsi qalır («ləğv edilib»), satış sətirləri (gəlir + müştəri borcu) götürülür —
     * onların surəti fakturada saxlanılır ki, sənəd açılanda görünsün. Sifariş «təhvil verilib» vəziyyətinə qayıdır.
     */
    public function cancelInvoice(SalesInvoice $invoice, ?int $userId = null, ?string $reason = null): void
    {
        $invoice->assertNotCancelled();
        PeriodLock::assertOpen($invoice->date);
        DB::transaction(function () use ($invoice, $userId, $reason) {
            $lines = DB::table('sales_lines as l')->leftJoin('delivery_note_lines as d', 'd.id', '=', 'l.delivery_note_line_id')
                ->where('l.sales_invoice_id', $invoice->id)->orderBy('l.row_no')->get(['l.*', 'd.sales_order_item_id']);
            DB::table('sales_lines')->where('sales_invoice_id', $invoice->id)->delete();
            if ($invoice->sales_order_id) {
                $order = SalesOrder::query()->find($invoice->sales_order_id);
                $order?->forceFill(['status' => 'shipped', 'invoice_no' => $order->invoice_no === $invoice->doc_no ? null : $order->invoice_no])->save();
            }
            $invoice->markCancelled($userId, $reason, ['cancelled_lines' => $lines->toJson(JSON_UNESCAPED_UNICODE)]);
        });
    }

    /**
     * Təhvil-təslimi ləğv edir (aktiv fakturası yoxdursa): sənəd qalır, stok hesablamasından çıxır (mal anbara qayıdır),
     * sifariş yenidən rezervdə olur.
     */
    public function cancelDelivery(DeliveryNote $note, ?int $userId = null, ?string $reason = null): void
    {
        $note->assertNotCancelled();
        if (SalesInvoice::query()->active()->where('delivery_note_id', $note->id)->exists()) {
            throw new RuntimeException(__('Bu təhvil-təslimə faktura bağlıdır — əvvəlcə fakturanı ləğv edin.'));
        }
        PeriodLock::assertOpen($note->date);
        DB::transaction(function () use ($note, $userId, $reason) {
            if ($note->sales_order_id) {
                SalesOrder::query()->whereKey($note->sales_order_id)->update(['status' => 'open']);
            }
            $note->markCancelled($userId, $reason);
        });
    }

    /**
     * Əl ilə sifarişi ləğv edir (yalnız rezervdə olan): rezerv azad olunur, sifariş və nömrəsi qalır.
     */
    public function cancelOrder(SalesOrder $order, ?int $userId = null, ?string $reason = null): void
    {
        $order->assertNotCancelled();
        if ($order->status !== 'open') {
            throw new RuntimeException(__('Təhvil verilmiş sifariş ləğv edilmir — əvvəlcə təhvil-təslim sənədini ləğv edin.'));
        }
        $order->markCancelled($userId, $reason, ['status' => 'cancelled']);
    }
}
