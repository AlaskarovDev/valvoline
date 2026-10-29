<?php

namespace App\Services;

use App\Models\WarehouseTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Anbarlar arası transfer (iki addımlı, 1C «ордерная схема» / Odoo «2-step» kimi):
 *  göndər  → mənbə anbardan «Yolda (tranzit)» anbarına (stock_transfers);
 *  qəbul et → tranzitdən təyinat anbarına faktiki qəbul edilən miqdar (qismən qəbul mümkündür);
 *  kəsir   → tranzitdə qalan fərq silinmə aktı ilə silinir.
 * Maya FIFO ilə CostingService-də daşınır (transfer mənfəətə təsir etmir).
 */
class WarehouseTransferService
{
    public const PREFIX = 'AT-';

    public function __construct(private SalesDocumentService $documents, private StockWriteOffService $writeOffs) {}

    public static function transitId(): int
    {
        return (int) DB::table('warehouses')->where('type', 'transit')->value('id');
    }

    /**
     * Mənbə anbarda göndərmək üçün sərbəst qalıq: real stok − açıq sifarişlərdə rezerv.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, float>
     */
    public function available(int $warehouseId, ?array $productIds = null): array
    {
        $reserved = $this->documents->reserved();
        $out = [];
        foreach (DB::table('stock_balances')->where('warehouse_id', $warehouseId)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: [0]))->get(['product_id', 'qty']) as $r) {
            $out[(int) $r->product_id] = round((float) $r->qty - ($reserved[$r->product_id][$warehouseId] ?? 0), 4);
        }

        return $out;
    }

    public function send(WarehouseTransfer $transfer, ?int $userId, ?Carbon $date = null, bool $allowNegative = false): void
    {
        if ($transfer->status !== 'draft') {
            throw new RuntimeException(__('Yalnız qaralama transfer göndərilir.'));
        }
        $lines = DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $transfer->id)->orderBy('row_no')->get();
        if ($lines->isEmpty()) {
            throw new RuntimeException(__('Transferdə mal yoxdur.'));
        }
        if (! $allowNegative) {
            $available = $this->available($transfer->from_warehouse_id, $lines->pluck('product_id')->all());
            $short = $lines->groupBy('product_id')->filter(fn ($g, $id) => $g->sum('qty') > ($available[$id] ?? 0) + 1e-6)
                ->map(fn ($g, $id) => $g->first()->product_code.' ('.__('sərbəst').' '.rtrim(rtrim(number_format(max($available[$id] ?? 0, 0), 2, ',', ''), '0'), ',').')');
            if ($short->isNotEmpty()) {
                throw new RuntimeException(__('Mənbə anbarda kifayət qədər sərbəst qalıq yoxdur: :items', ['items' => $short->implode(', ')]));
            }
        }
        $date ??= Carbon::parse($transfer->date);
        DB::transaction(function () use ($transfer, $lines, $userId, $date) {
            $this->move($transfer, $lines->map(fn ($l) => [$l, (float) $l->qty]), $transfer->from_warehouse_id, self::transitId(), $date);
            $transfer->forceFill(['status' => 'sent', 'sent_date' => $date->toDateString(), 'sent_by' => $userId, 'sent_at' => now()])->save();
        });
    }

    /**
     * @param  array<int, float|int|string|null>  $received  line id => qəbul edilən miqdar (null = göndərilənin hamısı)
     * @param  array<int, string|null>  $notes
     */
    public function receive(WarehouseTransfer $transfer, ?int $userId, array $received = [], array $notes = [], ?Carbon $date = null): void
    {
        if (! in_array($transfer->status, ['sent', 'partial'], true)) {
            throw new RuntimeException(__('Yalnız göndərilmiş transfer qəbul edilir.'));
        }
        $date ??= now()->startOfDay()->max(Carbon::parse($transfer->sent_date ?? $transfer->date));
        $lines = DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $transfer->id)->orderBy('row_no')->get();
        DB::transaction(function () use ($transfer, $lines, $received, $notes, $userId, $date) {
            $moves = [];
            foreach ($lines as $line) {
                $open = (float) $line->qty - (float) ($line->received_qty ?? 0) - (float) $line->written_off_qty;
                if ($open <= 1e-6) {
                    continue;
                }
                $qty = array_key_exists($line->id, $received) && $received[$line->id] !== null && $received[$line->id] !== ''
                    ? (float) $received[$line->id] : $open;
                if ($qty < 0 || $qty > $open + 1e-6) {
                    throw new RuntimeException(__(':code: qəbul miqdarı 0 ilə :max arasında olmalıdır.', ['code' => $line->product_code, 'max' => rtrim(rtrim(number_format($open, 2, ',', ''), '0'), ',')]));
                }
                DB::table('warehouse_transfer_lines')->where('id', $line->id)->update([
                    'received_qty' => round((float) ($line->received_qty ?? 0) + $qty, 4),
                    'discrepancy_note' => ($notes[$line->id] ?? null) ?: $line->discrepancy_note,
                    'updated_at' => now(),
                ]);
                if ($qty > 1e-6) {
                    $moves[] = [$line, $qty];
                }
            }
            $this->move($transfer, collect($moves), self::transitId(), $transfer->to_warehouse_id, $date);
            $this->refreshStatus($transfer, $userId, $date);
        });
    }

    /**
     * Qəbul edilməyən fərqi (tranzitdə qalan) kəsir kimi silir.
     */
    public function writeOffShortage(WarehouseTransfer $transfer, ?int $userId, ?string $note = null): ?int
    {
        $lines = DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $transfer->id)->get()
            ->map(fn ($l) => tap($l, fn ($x) => $x->open = round((float) $x->qty - (float) ($x->received_qty ?? 0) - (float) $x->written_off_qty, 4)))
            ->filter(fn ($l) => $l->open > 1e-6);
        if ($lines->isEmpty() || $transfer->status !== 'partial') {
            throw new RuntimeException(__('Silinəcək kəsir yoxdur.'));
        }

        return DB::transaction(function () use ($transfer, $lines, $userId, $note) {
            $writeOff = $this->writeOffs->create(self::transitId(), now()->startOfDay(), 'transfer_shortage',
                $lines->map(fn ($l) => ['product_id' => $l->product_id, 'qty' => $l->open, 'batch_id' => null])->values()->all(),
                $userId, trim(__('Transfer № :no üzrə kəsir', ['no' => $transfer->doc_no]).' '.$note), $transfer->id);
            foreach ($lines as $l) {
                DB::table('warehouse_transfer_lines')->where('id', $l->id)->update(['written_off_qty' => round((float) $l->written_off_qty + $l->open, 4), 'updated_at' => now()]);
            }
            $this->refreshStatus($transfer, $userId, now()->startOfDay());

            return $writeOff->id;
        });
    }

    public function cancel(WarehouseTransfer $transfer, ?string $reason = null): void
    {
        if ($transfer->status === 'draft' || ($transfer->status === 'sent' && ! DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $transfer->id)->where('received_qty', '>', 0)->exists())) {
            DB::transaction(function () use ($transfer, $reason) {
                DB::table('stock_transfers')->where('warehouse_transfer_id', $transfer->id)->delete();
                $transfer->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            });

            return;
        }
        throw new RuntimeException(__('Qəbul edilməyə başlanmış transfer ləğv edilmir — əks transfer edin.'));
    }

    /**
     * Doldurma tövsiyəsi (məs. səyyar maşın): təyinat anbarının son N gündəki satışı − onun mövcud qalığı,
     * mənbədə sərbəst qalıqla məhdud.
     *
     * @return list<array{product_id: int, code: string, name: string, qty: float, sold: float, dest_stock: float, available: float}>
     */
    public function suggestions(int $fromId, int $toId, int $days = 14): array
    {
        $since = now()->subDays($days - 1)->toDateString();
        $main = (int) DB::table('warehouses')->where('is_main', true)->value('id');
        $sold = DB::table('delivery_note_lines as l')->join('delivery_notes as n', 'n.id', '=', 'l.delivery_note_id')
            ->where('n.warehouse_id', $toId)->where('n.date', '>=', $since)->groupBy('l.product_id')->selectRaw('l.product_id, SUM(l.qty) AS qty')->pluck('qty', 'product_id');
        foreach (DB::table('sales_lines')->whereNull('delivery_note_line_id')->whereNotNull('product_id')->where('date', '>=', $since)
            ->where(fn ($q) => $toId === $main ? $q->whereNull('warehouse_id')->orWhere('warehouse_id', $toId) : $q->where('warehouse_id', $toId))
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty) AS qty')->pluck('qty', 'product_id') as $id => $qty) {
            $sold[$id] = (float) ($sold[$id] ?? 0) + (float) $qty;
        }
        $dest = DB::table('stock_balances')->where('warehouse_id', $toId)->pluck('qty', 'product_id');
        $available = $this->available($fromId);
        $products = DB::table('products')->whereIn('id', $sold->keys()->all() ?: [0])->where('is_active', true)->get(['id', 'code', 'name'])->keyBy('id');
        $out = [];
        foreach ($sold as $id => $qty) {
            $need = (float) $qty - max((float) ($dest[$id] ?? 0), 0);
            $can = min($need, max($available[$id] ?? 0, 0));
            if ($can > 0.0001 && isset($products[$id])) {
                $out[] = ['product_id' => (int) $id, 'code' => $products[$id]->code, 'name' => $products[$id]->name, 'qty' => round(ceil($can), 4),
                    'sold' => round((float) $qty, 2), 'dest_stock' => round((float) ($dest[$id] ?? 0), 2), 'available' => round((float) ($available[$id] ?? 0), 2)];
            }
        }
        usort($out, fn ($a, $b) => $b['sold'] <=> $a['sold']);

        return $out;
    }

    /**
     * @param  Collection<int, array{0: object, 1: float}>  $moves
     */
    private function move(WarehouseTransfer $transfer, $moves, int $fromId, int $toId, Carbon $date): void
    {
        $names = DB::table('warehouses')->whereIn('id', [$fromId, $toId])->pluck('name', 'id');
        $row = (int) DB::table('stock_transfers')->max('row_no');
        $now = now();
        foreach ($moves as [$line, $qty]) {
            DB::table('stock_transfers')->insert([
                'row_no' => ++$row, 'date' => $date->toDateString(), 'period' => $date->format('Y-m'), 'doc_no' => $transfer->doc_no, 'direction' => 'out',
                'from_name' => $names[$fromId] ?? null, 'to_name' => $names[$toId] ?? null, 'from_warehouse_id' => $fromId, 'to_warehouse_id' => $toId,
                'product_id' => $line->product_id, 'product_code' => $line->product_code, 'product_name' => $line->product_name, 'qty' => round($qty, 4),
                'note' => $transfer->note, 'status' => 'ok', 'warehouse_transfer_id' => $transfer->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function refreshStatus(WarehouseTransfer $transfer, ?int $userId, Carbon $date): void
    {
        $open = DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $transfer->id)->get()
            ->sum(fn ($l) => max(0, (float) $l->qty - (float) ($l->received_qty ?? 0) - (float) $l->written_off_qty));
        $transfer->forceFill([
            'status' => $open > 1e-6 ? 'partial' : 'received',
            'received_date' => $date->toDateString(), 'received_by' => $userId, 'received_at' => now(),
        ])->save();
    }
}
