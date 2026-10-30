<?php

namespace App\Services;

use App\Models\StockCount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sayım: miqdarlar qaralamaya yazılır, tamamlananda sistem qalığı ilə müqayisə edilir və
 * fərqlər üçün sayım artığı (SA+) / əskiyi (SAS) sənədləri StockDocumentService ilə yaradılır.
 */
class StockCountService
{
    /**
     * Barkod / kod → məhsul və vahid əmsalı (qutu barkodu = 12 ədəd).
     *
     * @return array{product_id: int, factor: float}|null
     */
    public function resolve(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $unit = DB::table('product_barcodes as b')->leftJoin('product_units as pu', 'pu.id', '=', 'b.product_unit_id')->where('b.barcode', $code)->first(['b.product_id', 'pu.factor']);
        if ($unit) {
            return ['product_id' => (int) $unit->product_id, 'factor' => (float) ($unit->factor ?? 1)];
        }
        $id = DB::table('products')->where('barcode', $code)->orWhere('code', $code)->value('id');

        return $id ? ['product_id' => (int) $id, 'factor' => 1.0] : null;
    }

    /**
     * Sətri artırır (skan) və ya miqdarı birbaşa yazır.
     */
    public function record(StockCount $count, int $productId, float $qty, bool $add, int $userId): float
    {
        if (! $count->isOpen()) {
            throw new RuntimeException(__('Sayım bağlıdır — dəyişmək olmaz.'));
        }

        return DB::transaction(function () use ($count, $productId, $qty, $add, $userId) {
            $line = DB::table('stock_count_lines')->where('stock_count_id', $count->id)->where('product_id', $productId)->lockForUpdate()->first();
            $new = round(max(0.0, ($add && $line ? (float) $line->counted_qty : 0.0) + $qty), 4);
            DB::table('stock_count_lines')->updateOrInsert(['stock_count_id' => $count->id, 'product_id' => $productId],
                ['counted_qty' => $new, 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => $line->created_at ?? now()]);

            return $new;
        });
    }

    /**
     * Sayılan və sistem qalığının müqayisəsi (tam sayımda sayılmayan qalıqlı mallar 0 sayılır).
     *
     * @return Collection<int, object>
     */
    public function comparison(StockCount $count): Collection
    {
        $counted = DB::table('stock_count_lines')->where('stock_count_id', $count->id)->get(['product_id', 'counted_qty', 'system_qty'])->keyBy('product_id');
        $system = $count->completed_at
            ? $counted->mapWithKeys(fn ($l) => [$l->product_id => (float) $l->system_qty])
            : DB::table('stock_balances')->where('warehouse_id', $count->warehouse_id)->pluck('qty', 'product_id')->map(fn ($q) => (float) $q);
        $ids = $count->scope === 'full' ? $counted->keys()->merge($system->filter(fn ($q) => abs($q) > 1e-6)->keys())->unique() : $counted->keys();
        $products = DB::table('products as p')->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')->whereIn('p.id', $ids->all() ?: [0])
            ->get(['p.id', 'p.code', 'p.name', 'p.current_avg_cost', 'u.name as unit'])->keyBy('id');

        return $ids->map(function ($id) use ($counted, $system, $products) {
            $p = $products[$id] ?? null;
            $c = (float) ($counted[$id]->counted_qty ?? 0);
            $s = round((float) ($system[$id] ?? 0), 4);

            return (object) ['product_id' => (int) $id, 'code' => $p?->code, 'name' => $p?->name, 'unit' => $p?->unit, 'counted' => $c, 'system' => $s,
                'diff' => round($c - $s, 4), 'value' => round(($c - $s) * (float) ($p?->current_avg_cost ?? 0), 2), 'is_counted' => isset($counted[$id])];
        })->sortBy('name')->values();
    }

    /**
     * Sayımı tamamlayır: fərq olan mallar üçün artıq / əskik sənədləri yaradılır, sistem qalığı sətirlərə yazılır.
     *
     * @return array{surplus: string|null, shortage: string|null}
     */
    public function complete(StockCount $count, int $userId): array
    {
        if (! $count->isOpen()) {
            throw new RuntimeException(__('Sayım bağlıdır — dəyişmək olmaz.'));
        }
        $rows = $this->comparison($count);
        if ($rows->isEmpty()) {
            throw new RuntimeException(__('Heç bir mal sayılmayıb.'));
        }
        $documents = app(StockDocumentService::class);
        $header = ['date' => $count->date->toDateString(), 'warehouse_id' => $count->warehouse_id, 'workplace_id' => $count->workplace_id, 'note' => __('Sayım № :no', ['no' => $count->doc_no])];

        return DB::transaction(function () use ($count, $rows, $documents, $header, $userId) {
            $made = ['surplus' => null, 'shortage' => null];
            foreach (['surplus' => fn ($r) => $r->diff > 1e-6, 'shortage' => fn ($r) => $r->diff < -1e-6] as $kind => $filter) {
                $lines = $rows->filter($filter)->map(fn ($r) => ['product_id' => $r->product_id, 'counted_qty' => $r->counted])->values()->all();
                if ($lines) {
                    $doc = $documents->create('count_'.$kind, $header, $lines, $userId);
                    $count->forceFill([$kind.'_document_id' => $doc->id]);
                    $made[$kind] = $doc->doc_no;
                }
            }
            foreach ($rows as $r) {
                DB::table('stock_count_lines')->updateOrInsert(['stock_count_id' => $count->id, 'product_id' => $r->product_id],
                    ['counted_qty' => $r->counted, 'system_qty' => $r->system, 'updated_at' => now()]);
            }
            $count->forceFill(['completed_at' => now()])->save();

            return $made;
        });
    }
}
