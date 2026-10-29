<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Partiyalar və son istifadə tarixləri (FEFO).
 * Satış və transferlər partiya göstərmədiyi üçün qalan stok FEFO prinsipi ilə bölüşdürülür:
 * ən tez bitən partiya ilk satılmış sayılır, deməli qalıq ən gec bitən partiyalardadır.
 * Qeydiyyatlı partiyası olmayan, amma saxlanma müddəti verilmiş mallar üçün idxal tarixindən təxmini partiyalar qurulur.
 */
class BatchService
{
    public const DEFAULT_ALERT_DAYS = 90;

    /**
     * Hər partiyanın qalığı.
     *
     * @return Collection<int, object>
     */
    public function batches(?Carbon $today = null): Collection
    {
        $today ??= now()->startOfDay();
        $products = DB::table('products as p')->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->get(['p.id', 'p.code', 'p.name', 'p.stock_qty', 'p.current_avg_cost', 'p.shelf_life_months', 'p.expiry_alert_days', 'p.is_active', 'u.name as unit_name'])->keyBy('id');
        $registered = DB::table('product_batches as b')->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->get(['b.*', 'w.name as warehouse_name'])->groupBy('product_id');

        // qeydiyyatlı partiyası olmayan və saxlanma müddəti olan mallar: idxal (alış) sətirlərindən təxmini partiya
        $estimated = collect();
        $withShelf = $products->filter(fn ($p) => (int) $p->shelf_life_months > 0 && ! isset($registered[$p->id]));
        if ($withShelf->isNotEmpty()) {
            $estimated = DB::table('purchase_lines')->whereIn('product_id', $withShelf->keys()->all())->where('qty', '>', 0)
                ->get(['id', 'product_id', 'batch_no', 'date', 'qty'])
                ->map(fn ($l) => (object) [
                    'id' => null, 'product_id' => (int) $l->product_id, 'batch_no' => 'P-'.$l->batch_no, 'production_date' => null,
                    'expiry_date' => Carbon::parse($l->date)->addMonths((int) $withShelf[$l->product_id]->shelf_life_months)->toDateString(),
                    'expiry_estimated' => true, 'qty' => (float) $l->qty, 'written_off_qty' => 0.0, 'warehouse_name' => null,
                    'received_date' => (string) $l->date, 'supplier' => null, 'note' => __('İdxal tarixi + saxlanma müddəti'), 'source' => 'estimated',
                ])->groupBy('product_id');
        }

        $out = collect();
        foreach ($registered->union($estimated) as $productId => $rows) {
            $product = $products[$productId] ?? null;
            if (! $product) {
                continue;
            }
            $left = max((float) $product->stock_qty, 0.0);
            // FEFO: qalıq ən gec bitən partiyalara yazılır (tarixsiz partiya ən sonda bitən sayılır)
            $sorted = $rows->sortByDesc(fn ($b) => $b->expiry_date ?? '9999-12-31')->values();
            foreach ($sorted as $b) {
                $capacity = max(0.0, (float) $b->qty - (float) $b->written_off_qty);
                $remaining = min($capacity, $left);
                $left -= $remaining;
                $days = $b->expiry_date ? (int) $today->diffInDays(Carbon::parse($b->expiry_date), false) : null;
                $alert = (int) ($product->expiry_alert_days ?? self::DEFAULT_ALERT_DAYS);
                $out->push((object) [
                    'batch_id' => $b->id ?? null, 'product_id' => (int) $productId, 'code' => $product->code, 'name' => $product->name, 'unit_name' => $product->unit_name,
                    'batch_no' => $b->batch_no, 'production_date' => $b->production_date, 'expiry_date' => $b->expiry_date,
                    'estimated' => (bool) $b->expiry_estimated, 'source' => $b->source ?? 'registered',
                    'qty' => (float) $b->qty, 'written_off' => (float) $b->written_off_qty, 'remaining' => round($remaining, 4),
                    'days_left' => $days, 'value' => round($remaining * (float) ($product->current_avg_cost ?? 0), 2),
                    'warehouse_name' => $b->warehouse_name ?? null, 'received_date' => $b->received_date ?? null, 'supplier' => $b->supplier ?? null, 'note' => $b->note ?? null,
                    'state' => $remaining <= 1e-6 ? 'used' : ($days === null ? 'no_date' : ($days < 0 ? 'expired' : ($days <= $alert ? 'soon' : 'ok'))),
                    'alert_days' => $alert,
                ]);
            }
        }

        return $out;
    }

    /**
     * Mal üzrə vaxtı bitməmiş qalıq (yalnız qeydiyyatlı partiyası olan mallar): [product_id => qty].
     *
     * @return array<int, array{valid: float, expired: float}>
     */
    public function validStock(?Carbon $today = null): array
    {
        $out = [];
        foreach ($this->batches($today)->where('source', 'registered')->groupBy('product_id') as $id => $rows) {
            $expired = $rows->where('state', 'expired')->sum('remaining');
            $out[$id] = ['valid' => round($rows->sum('remaining') - $expired, 4), 'expired' => round($expired, 4)];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function stateLabels(): array
    {
        return ['expired' => __('Vaxtı bitib'), 'soon' => __('Tezliklə bitir'), 'ok' => __('Normal'), 'no_date' => __('Tarixsiz'), 'used' => __('Satılıb / qalmayıb')];
    }
}
