<?php

namespace App\Services;

use App\Models\StockDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stok sənədləri: açılış (stoka ilkin qalıq + maya), sayım artığı / əskiyi, istehsal itkisi, sərf.
 * Sayım sənədində sayılan miqdar yazılırsa fərq (sayılan − sistemdəki) avtomatik hesablanır.
 */
class StockDocumentService
{
    /**
     * @param  array{date: string, warehouse_id: int, workplace_id: int|null, purpose?: string|null, note?: string|null}  $header
     * @param  list<array{product_id: int, qty?: float|int|string|null, counted_qty?: float|int|string|null, unit_cost?: float|int|string|null, note?: string|null}>  $lines
     */
    public function create(string $type, array $header, array $lines, ?int $userId): StockDocument
    {
        [$prefix, $sign] = StockDocument::TYPES[$type];
        $date = Carbon::parse($header['date']);
        $system = DB::table('stock_balances')->where('warehouse_id', $header['warehouse_id'])
            ->whereIn('product_id', array_column($lines, 'product_id'))->pluck('qty', 'product_id');
        $products = DB::table('products')->whereIn('id', array_column($lines, 'product_id'))->get(['id', 'code', 'name', 'current_avg_cost'])->keyBy('id');

        $rows = [];
        foreach ($lines as $i => $l) {
            $p = $products[(int) $l['product_id']];
            $systemQty = round((float) ($system[$p->id] ?? 0), 4);
            $counted = isset($l['counted_qty']) && $l['counted_qty'] !== '' && $l['counted_qty'] !== null ? (float) $l['counted_qty'] : null;
            if (StockDocument::isCount($type) && $counted !== null) {
                $diff = round($counted - $systemQty, 4);
                if ($diff * $sign <= 0) {
                    throw new RuntimeException($diff === 0.0
                        ? __(':code: sayılan miqdar sistemdəki qalığa bərabərdir (:qty) — sənəd lazım deyil.', ['code' => $p->code, 'qty' => $this->fmt($systemQty)])
                        : __(':code: sayılan :c, sistemdə :s — bu :type deyil.', ['code' => $p->code, 'c' => $this->fmt($counted), 's' => $this->fmt($systemQty),
                            'type' => mb_strtolower(StockDocument::typeLabels()[$sign > 0 ? 'count_shortage' : 'count_surplus'])]));
                }
                $qty = abs($diff);
            } else {
                $qty = (float) ($l['qty'] ?? 0);
            }
            if ($qty <= 0) {
                throw new RuntimeException(__(':code: miqdar 0-dan böyük olmalıdır.', ['code' => $p->code]));
            }
            $cost = $type === 'opening' ? (float) ($l['unit_cost'] ?? 0) : ($p->current_avg_cost !== null ? (float) $p->current_avg_cost : null);
            $rows[] = ['product' => $p, 'system_qty' => StockDocument::isCount($type) ? $systemQty : null, 'counted_qty' => $counted,
                'qty' => round($qty, 4), 'unit_cost' => $cost, 'note' => $l['note'] ?? null];
        }

        return DB::transaction(function () use ($type, $sign, $date, $header, $rows, $userId) {
            $now = now();
            $id = DB::table('stock_documents')->insertGetId([
                'doc_no' => app(NumberingService::class)->next('stock_doc_'.$type, ['date' => $date, 'workplace_id' => $header['workplace_id'] ?? null, 'warehouse_id' => $header['warehouse_id']]),
                'type' => $type, 'date' => $date->toDateString(),
                'warehouse_id' => $header['warehouse_id'], 'workplace_id' => $header['workplace_id'] ?? null,
                'purpose' => $type === 'consumption' ? ($header['purpose'] ?? null) : null, 'note' => $header['note'] ?? null,
                'total_qty' => round(array_sum(array_column($rows, 'qty')), 4),
                'total_cost' => round(array_sum(array_map(fn ($r) => $r['qty'] * ($r['unit_cost'] ?? 0), $rows)), 4),
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $doc = StockDocument::query()->findOrFail($id);
            $adjRow = (int) DB::table('stock_adjustments')->max('row_no');
            foreach ($rows as $n => $r) {
                DB::table('stock_document_lines')->insert([
                    'stock_document_id' => $id, 'row_no' => $n + 1, 'product_id' => $r['product']->id, 'product_code' => $r['product']->code,
                    'product_name' => $r['product']->name, 'system_qty' => $r['system_qty'], 'counted_qty' => $r['counted_qty'], 'qty' => $r['qty'],
                    'unit_cost' => $r['unit_cost'], 'note' => $r['note'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($type !== 'opening') {
                    DB::table('stock_adjustments')->insert([
                        'row_no' => ++$adjRow, 'date' => $date->toDateString(), 'period' => $date->format('Y-m'), 'warehouse_id' => $header['warehouse_id'],
                        'product_id' => $r['product']->id, 'product_code' => $r['product']->code, 'product_name' => $r['product']->name,
                        'computed_qty' => $r['system_qty'], 'counted_qty' => $r['counted_qty'], 'qty' => $sign * $r['qty'],
                        'reason' => StockDocument::typeLabels()[$type].' '.$doc->doc_no, 'status' => 'ok', 'stock_document_id' => $id,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            return $doc;
        });
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
    }
}
