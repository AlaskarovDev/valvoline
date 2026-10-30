<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesReturnDocument;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Müştəri qaytarma qaiməsi. Fakturaya istinad olduqda qiymət fakturadakı (endirimli, ƏDV daxil) qiymətdir və
 * qaytarılan miqdar fakturada qalandan çox ola bilməz. Təsir: sales_returns (borc azalır, mal anbara qayıdır).
 */
class SalesReturnService
{
    /**
     * Fakturanın sətirləri: hələ qaytarıla bilən miqdarla.
     *
     * @return list<array{sales_line_id: int, product_id: int, code: string, name: string|null, sold: float, returned: float, available: float, price: float}>
     */
    public function invoiceLines(SalesInvoice $invoice): array
    {
        $returned = DB::table('sales_return_document_lines as l')->join('sales_return_documents as d', 'd.id', '=', 'l.sales_return_document_id')
            ->whereNull('d.cancelled_at')->where('d.sales_invoice_id', $invoice->id)->groupBy('l.sales_line_id')->selectRaw('l.sales_line_id, SUM(l.qty) AS qty')->pluck('qty', 'sales_line_id');

        return DB::table('sales_lines')->where('sales_invoice_id', $invoice->id)->where('op_type', 'sale')->orderBy('row_no')
            ->get(['id', 'product_id', 'product_code', 'product_name', 'qty', 'net_amount'])
            ->map(fn ($l) => ['sales_line_id' => $l->id, 'product_id' => (int) $l->product_id, 'code' => $l->product_code, 'name' => $l->product_name,
                'sold' => (float) $l->qty, 'returned' => (float) ($returned[$l->id] ?? 0), 'available' => round((float) $l->qty - (float) ($returned[$l->id] ?? 0), 4),
                'price' => (float) $l->qty > 0 ? round((float) $l->net_amount / (float) $l->qty, 4) : 0.0])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $header  date, customer_id, warehouse_id, workplace_id, sales_invoice_id, reason, note
     * @param  list<array{product_id: int|string, qty: float|int|string, price?: float|int|string|null, sales_line_id?: int|string|null}>  $lines
     */
    public function create(array $header, array $lines, ?int $userId): SalesReturnDocument
    {
        $date = Carbon::parse($header['date']);
        PeriodLock::assertOpen($date);
        $customer = Customer::query()->findOrFail($header['customer_id']);
        $invoice = ! empty($header['sales_invoice_id']) ? SalesInvoice::query()->active()->findOrFail($header['sales_invoice_id']) : null;
        if ($invoice && (int) $invoice->customer_id !== $customer->id) {
            throw new RuntimeException(__('Faktura bu müştəriyə aid deyil.'));
        }
        $available = $invoice ? collect($this->invoiceLines($invoice))->keyBy('sales_line_id') : collect();
        $products = DB::table('products')->whereIn('id', array_column($lines, 'product_id'))->get(['id', 'code', 'name', 'vat_mode', 'vat_rate'])->keyBy('id');

        $rows = [];
        foreach (array_values($lines) as $i => $l) {
            $qty = round((float) $l['qty'], 4);
            if ($qty <= 0) {
                continue;
            }
            $p = $products[(int) $l['product_id']];
            $lineId = $invoice ? (int) ($l['sales_line_id'] ?? 0) : null;
            if ($invoice) {
                $src = $available[$lineId] ?? null;
                if (! $src || $src['product_id'] !== $p->id) {
                    throw new RuntimeException(__(':code fakturada yoxdur.', ['code' => $p->code]));
                }
                if ($qty > $src['available'] + 1e-6) {
                    throw new RuntimeException(__(':code: fakturadan ən çox :qty qaytarmaq olar.', ['code' => $p->code, 'qty' => rtrim(rtrim(number_format($src['available'], 2, ',', ''), '0'), ',')]));
                }
                $price = $src['price'];
            } else {
                $price = round((float) ($l['price'] ?? 0), 4);
            }
            $amount = round($qty * $price, 4);
            $vat = Vat::split($amount, $p->vat_mode, $p->vat_rate);
            $rows[] = ['p' => $p, 'line_id' => $lineId, 'qty' => $qty, 'price' => $price, 'amount' => $amount, 'vat' => $vat];
        }
        if (! $rows) {
            throw new RuntimeException(__('Qaytarılan mal yoxdur.'));
        }

        return DB::transaction(function () use ($header, $date, $customer, $invoice, $rows, $userId) {
            $managerId = $invoice->manager_id ?? $customer->manager_id;
            $doc = SalesReturnDocument::query()->create([
                'doc_no' => app(NumberingService::class)->next('sales_return', ['date' => $date, 'warehouse_id' => $header['warehouse_id'], 'workplace_id' => $header['workplace_id'] ?? null, 'manager_id' => $managerId]),
                'date' => $date->toDateString(), 'customer_id' => $customer->id, 'manager_id' => $managerId, 'warehouse_id' => $header['warehouse_id'],
                'workplace_id' => $header['workplace_id'] ?? null, 'sales_invoice_id' => $invoice?->id, 'reason' => $header['reason'], 'note' => $header['note'] ?? null,
                'total_amount' => round(array_sum(array_column($rows, 'amount')), 4), 'vat_amount' => round(array_sum(array_map(fn ($r) => $r['vat']['vat'], $rows)), 4),
                'created_by' => $userId,
            ]);
            $managerName = $managerId ? DB::table('managers')->where('id', $managerId)->value('name') : null;
            $rowNo = (int) DB::table('sales_returns')->max('row_no');
            $now = now();
            foreach ($rows as $i => $r) {
                DB::table('sales_return_document_lines')->insert([
                    'sales_return_document_id' => $doc->id, 'row_no' => $i + 1, 'sales_line_id' => $r['line_id'], 'product_id' => $r['p']->id,
                    'product_code' => $r['p']->code, 'product_name' => $r['p']->name, 'qty' => $r['qty'], 'price' => $r['price'], 'net_amount' => $r['amount'],
                    'vat_mode' => $r['p']->vat_mode, 'vat_rate' => $r['vat']['rate'], 'vat_amount' => $r['vat']['vat'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('sales_returns')->insert([
                    'row_no' => ++$rowNo, 'date' => $doc->date->toDateString(), 'period' => $doc->date->format('Y-m'), 'return_no' => $doc->doc_no,
                    'manager_id' => $managerId, 'manager_name' => $managerName, 'customer_id' => $customer->id, 'customer_code' => $customer->code,
                    'customer_name' => $customer->name, 'original_customer_name' => $customer->name,
                    'product_id' => $r['p']->id, 'product_code' => $r['p']->code, 'product_name' => $r['p']->name,
                    'qty' => $r['qty'], 'price' => $r['price'], 'discount_pct' => 0, 'net_amount' => $r['amount'], 'op_type' => 'customer',
                    'reason' => SalesReturnDocument::reasons()[$doc->reason] ?? $doc->reason, 'note' => $doc->note, 'sales_order_id' => $invoice?->sales_order_id,
                    'status' => 'ok', 'sales_return_document_id' => $doc->id, 'warehouse_id' => $doc->warehouse_id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return $doc;
        });
    }

    public function cancel(SalesReturnDocument $doc, ?int $userId = null, ?string $reason = null): void
    {
        $doc->assertNotCancelled();
        PeriodLock::assertOpen($doc->date);
        DB::transaction(function () use ($doc, $userId, $reason) {
            DB::table('sales_returns')->where('sales_return_document_id', $doc->id)->delete();
            $doc->markCancelled($userId, $reason);
        });
    }
}
