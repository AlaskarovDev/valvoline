<?php

namespace App\Services;

use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Alış sənədləri.
 *  Mədaxil (receipt): mal anbara daxil olur — purchase_lines-a yazılır, FIFO mayası = (ƏDV-siz məbləğ × məzənnə + paylanmış
 *    gömrük / daşınma / digər xərc) / miqdar. ƏDV mayaya daxil deyil (əvəzləşdirilir), təchizatçı borcuna daxildir.
 *  Qaytarma (return): mal təchizatçıya qaytarılır — stock_adjustments-a mənfi yazılır, təchizatçı borcu azalır.
 */
class PurchaseService
{
    /**
     * @param  array<string, mixed>  $header  date, supplier_id, warehouse_id, workplace_id, rate, vat_included, customs_cost, transport_cost, other_cost, supplier_invoice_no, supplier_invoice_date, original_document_id, note
     * @param  list<array{product_id: int|string, qty: float|int|string, price: float|int|string, note?: string|null}>  $lines
     */
    public function create(string $type, array $header, array $lines, ?int $userId): PurchaseDocument
    {
        $date = Carbon::parse($header['date']);
        PeriodLock::assertOpen($date);
        $supplier = Supplier::query()->findOrFail($header['supplier_id']);
        $currency = $supplier->currency ?: 'AZN';
        $rate = $currency === 'AZN' ? 1.0 : (float) ($header['rate'] ?? 0);
        if ($rate <= 0) {
            throw new RuntimeException(__('Valyuta məzənnəsi yazılmayıb (:cur).', ['cur' => $currency]));
        }
        $vatIncluded = (bool) ($header['vat_included'] ?? false);
        $extras = [];
        foreach (['customs_cost', 'transport_cost', 'other_cost'] as $key) {
            $extras[$key] = $type === 'receipt' ? round((float) ($header[$key] ?? 0), 4) : 0.0;
        }
        $products = DB::table('products')->whereIn('id', array_column($lines, 'product_id'))->get(['id', 'code', 'name', 'vat_mode', 'vat_rate'])->keyBy('id');

        $rows = [];
        foreach (array_values($lines) as $i => $l) {
            $p = $products[(int) $l['product_id']];
            $qty = round((float) $l['qty'], 4);
            $price = round((float) $l['price'], 6);
            $vatRate = Vat::rate($p->vat_mode, $p->vat_rate);
            $gross = $qty * $price;
            $vat = $vatIncluded ? $gross * $vatRate / (100 + $vatRate) : $gross * $vatRate / 100;
            $net = $vatIncluded ? $gross - $vat : $gross;
            $rows[] = ['p' => $p, 'row_no' => $i + 1, 'qty' => $qty, 'price' => $price, 'amount' => round($net, 4), 'vat_rate' => $vatRate, 'vat_amount' => round($vat, 4), 'note' => $l['note'] ?? null];
        }
        $goods = array_sum(array_column($rows, 'amount'));
        $extraTotal = array_sum($extras);
        // əlavə xərc sətirlərə AZN dəyəri nisbətində paylanır, yuvarlaq qalığı son sətrə
        $allocated = 0.0;
        foreach ($rows as $i => &$r) {
            $share = $goods > 0 ? ($i === array_key_last($rows) ? $extraTotal - $allocated : round($extraTotal * $r['amount'] / $goods, 4)) : 0.0;
            $allocated += $share;
            $r['extra'] = round($share, 4);
            $r['unit_cost'] = $r['qty'] > 0 ? round(($r['amount'] * $rate + $r['extra']) / $r['qty'], 4) : 0.0;
        }
        unset($r);

        return DB::transaction(function () use ($type, $header, $date, $supplier, $currency, $rate, $vatIncluded, $extras, $rows, $goods, $userId) {
            $vatTotal = array_sum(array_column($rows, 'vat_amount'));
            $doc = PurchaseDocument::query()->create([
                'doc_no' => app(NumberingService::class)->next('purchase_'.$type, ['date' => $date, 'warehouse_id' => $header['warehouse_id'], 'workplace_id' => $header['workplace_id'] ?? null]),
                'type' => $type, 'date' => $date->toDateString(), 'supplier_id' => $supplier->id, 'warehouse_id' => $header['warehouse_id'],
                'workplace_id' => $header['workplace_id'] ?? null, 'original_document_id' => $header['original_document_id'] ?? null,
                'purchase_order_id' => $type === 'receipt' ? ($header['purchase_order_id'] ?? null) : null,
                'currency' => $currency, 'rate' => $rate, 'vat_included' => $vatIncluded,
                'supplier_invoice_no' => $header['supplier_invoice_no'] ?? null, 'supplier_invoice_date' => $header['supplier_invoice_date'] ?? null,
                'due_date' => $type === 'receipt' ? $date->copy()->addDays((int) $supplier->payment_days)->toDateString() : null,
                'goods_amount' => round($goods, 4), 'vat_amount' => round($vatTotal, 4), 'total_amount' => round($goods + $vatTotal, 4),
                'total_cost_azn' => round(array_sum(array_map(fn ($r) => $r['unit_cost'] * $r['qty'], $rows)), 4),
                'note' => $header['note'] ?? null, 'created_by' => $userId,
            ] + $extras);
            $now = now();
            $row = (int) DB::table($type === 'receipt' ? 'purchase_lines' : 'stock_adjustments')->max('row_no');
            foreach ($rows as $r) {
                DB::table('purchase_document_lines')->insert([
                    'purchase_document_id' => $doc->id, 'row_no' => $r['row_no'], 'product_id' => $r['p']->id, 'product_code' => $r['p']->code, 'product_name' => $r['p']->name,
                    'qty' => $r['qty'], 'price' => $r['price'], 'amount' => $r['amount'], 'vat_rate' => $r['vat_rate'], 'vat_amount' => $r['vat_amount'],
                    'extra_cost_azn' => $r['extra'], 'unit_cost_azn' => $r['unit_cost'], 'note' => $r['note'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                $common = ['row_no' => ++$row, 'date' => $doc->date->toDateString(), 'period' => $doc->date->format('Y-m'), 'warehouse_id' => $doc->warehouse_id,
                    'product_id' => $r['p']->id, 'product_code' => $r['p']->code, 'product_name' => $r['p']->name, 'status' => 'ok',
                    'purchase_document_id' => $doc->id, 'created_at' => $now, 'updated_at' => $now];
                if ($type === 'receipt') {
                    DB::table('purchase_lines')->insert($common + ['batch_no' => 1000000 + $doc->id, 'doc_no' => $doc->doc_no, 'qty' => $r['qty'],
                        'unit_cost' => $r['unit_cost'], 'total_cost' => round($r['unit_cost'] * $r['qty'], 4)]);
                } else {
                    DB::table('stock_adjustments')->insert($common + ['qty' => -$r['qty'], 'reason' => __('Təchizatçıya qaytarma').' '.$doc->doc_no]);
                }
            }

            return $doc;
        });
    }

    /**
     * Sənədi ləğv edir: sənəd qalır, stok təsiri (mədaxil sətirləri / qaytarma düzəlişləri) götürülür, təchizatçı borcundan çıxır.
     */
    public function cancel(PurchaseDocument $doc, ?int $userId = null, ?string $reason = null): void
    {
        $doc->assertNotCancelled();
        PeriodLock::assertOpen($doc->date);
        if ($doc->type === 'receipt' && PurchaseDocument::query()->active()->where('original_document_id', $doc->id)->exists()) {
            throw new RuntimeException(__('Bu mədaxilə bağlı aktiv qaytarma var — əvvəlcə onu ləğv edin.'));
        }
        DB::transaction(function () use ($doc, $userId, $reason) {
            DB::table('purchase_lines')->where('purchase_document_id', $doc->id)->delete();
            DB::table('stock_adjustments')->where('purchase_document_id', $doc->id)->delete();
            $doc->markCancelled($userId, $reason);
        });
    }
}
