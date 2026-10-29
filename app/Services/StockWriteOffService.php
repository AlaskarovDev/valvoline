<?php

namespace App\Services;

use App\Models\StockWriteOff;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Silinmə aktı (vaxtı bitmiş / zədələnmiş / kəsir): stock_adjustments-a mənfi düzəliş yazır,
 * partiyanın silinmiş miqdarını artırır. Maya CostingService-də hesablanır (xərc kimi).
 */
class StockWriteOffService
{
    public const PREFIX = 'SA-';

    /**
     * @param  list<array{product_id: int, qty: float|int|string, batch_id?: int|null}>  $lines
     */
    public function create(int $warehouseId, Carbon $date, string $reason, array $lines, ?int $userId, ?string $note = null, ?int $transferId = null, ?int $workplaceId = null): StockWriteOff
    {
        return DB::transaction(function () use ($warehouseId, $date, $reason, $lines, $userId, $note, $transferId) {
            $products = DB::table('products')->whereIn('id', array_column($lines, 'product_id'))->get(['id', 'code', 'name', 'current_avg_cost'])->keyBy('id');
            $doc = StockWriteOff::query()->create([
                'doc_no' => $this->nextNo(), 'date' => $date->toDateString(), 'warehouse_id' => $warehouseId, 'reason' => $reason,
                'note' => $note, 'warehouse_transfer_id' => $transferId, 'created_by' => $userId,
                'workplace_id' => $workplaceId ?? ($transferId ? DB::table('warehouse_transfers')->where('id', $transferId)->value('workplace_id') : null)
                    ?? Workplace::defaultIdFor($userId ? User::query()->find($userId) : null),
                'total_qty' => round(array_sum(array_map(fn ($l) => (float) $l['qty'], $lines)), 4),
            ]);
            $row = (int) DB::table('stock_adjustments')->max('row_no');
            $now = now();
            $total = 0.0;
            foreach ($lines as $l) {
                $p = $products[$l['product_id']];
                $qty = round((float) $l['qty'], 4);
                $cost = $p->current_avg_cost !== null ? (float) $p->current_avg_cost : null;
                $total += $qty * ($cost ?? 0);
                DB::table('stock_write_off_lines')->insert([
                    'stock_write_off_id' => $doc->id, 'product_id' => $p->id, 'product_batch_id' => $l['batch_id'] ?? null,
                    'product_code' => $p->code, 'product_name' => $p->name, 'qty' => $qty, 'unit_cost' => $cost, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('stock_adjustments')->insert([
                    'row_no' => ++$row, 'date' => $date->toDateString(), 'period' => $date->format('Y-m'), 'warehouse_id' => $warehouseId,
                    'product_id' => $p->id, 'product_code' => $p->code, 'product_name' => $p->name, 'qty' => -$qty,
                    'reason' => __('Silinmə aktı').' '.$doc->doc_no, 'status' => 'ok', 'stock_write_off_id' => $doc->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if (! empty($l['batch_id'])) {
                    DB::table('product_batches')->where('id', $l['batch_id'])->increment('written_off_qty', $qty);
                }
            }
            $doc->forceFill(['total_cost' => round($total, 4)])->save();

            return $doc;
        });
    }

    public function cancel(StockWriteOff $doc): void
    {
        DB::transaction(function () use ($doc) {
            foreach (DB::table('stock_write_off_lines')->where('stock_write_off_id', $doc->id)->whereNotNull('product_batch_id')->get() as $l) {
                DB::table('product_batches')->where('id', $l->product_batch_id)->decrement('written_off_qty', (float) $l->qty);
            }
            if ($doc->warehouse_transfer_id) {
                foreach (DB::table('stock_write_off_lines')->where('stock_write_off_id', $doc->id)->get() as $l) {
                    $line = DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $doc->warehouse_transfer_id)->where('product_id', $l->product_id)->where('written_off_qty', '>', 0)->first();
                    if ($line) {
                        DB::table('warehouse_transfer_lines')->where('id', $line->id)->update(['written_off_qty' => max(0, (float) $line->written_off_qty - (float) $l->qty)]);
                    }
                }
                DB::table('warehouse_transfers')->where('id', $doc->warehouse_transfer_id)->where('status', 'received')->update(['status' => 'partial']);
            }
            $doc->delete();
        });
    }

    public function nextNo(): string
    {
        $last = DB::table('stock_write_offs')->where('doc_no', 'like', self::PREFIX.'%')->pluck('doc_no')
            ->map(fn ($no) => (int) substr($no, strlen(self::PREFIX)))->max() ?? 0;

        return self::PREFIX.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        return ['expired' => __('Vaxtı bitib'), 'damaged' => __('Zədələnib'), 'shortage' => __('Kəsir (sayım)'), 'transfer_shortage' => __('Transfer kəsiri'), 'other' => __('Digər')];
    }
}
