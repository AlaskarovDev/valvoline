<?php

namespace App\Services;

use App\Reports\ReportFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Anbar qalığı (istənilən tarixə), dəyəri, dövriyyə günləri və «az qalıb» xəbərdarlığı.
 * Bütün rəqəmlər stock_movements jurnalından oxunur.
 */
class StockService
{
    /** Dövriyyə: qalıq / son 90 günün orta gündəlik satışı. */
    public const TURNOVER_WINDOW_DAYS = 90;

    /** «Az qalıb»: qalıq bu qədər günlük satışdan azdırsa (mal üçün ayrıca hədd yoxdursa). */
    public const LOW_STOCK_DAYS = 14;

    /**
     * @return Collection<int, object>
     */
    public function balances(ReportFilters $filters, ?Carbon $asOf = null, ?int $warehouseId = null): Collection
    {
        $asOf ??= now()->endOfDay();
        $date = $asOf->toDateString();

        $movements = DB::table('stock_movements')->where('date', '<=', $date);
        if ($warehouseId !== null) {
            $movements->where('warehouse_id', $warehouseId);
        }
        $qty = (clone $movements)->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty) AS qty, MAX(date) AS last_date')->get()->keyBy('product_id');

        // orta maya mal üzrədir (anbardan asılı deyil): tarixə qədər son hərəkətin running_avg_cost-u
        $lastSeq = DB::table('stock_movements')->where('date', '<=', $date)->groupBy('product_id')->selectRaw('product_id, MAX(seq) AS seq');
        $avg = DB::table('stock_movements as m')
            ->joinSub($lastSeq, 'x', fn ($j) => $j->on('x.product_id', '=', 'm.product_id')->on('x.seq', '=', 'm.seq'))
            ->pluck('m.running_avg_cost', 'm.product_id');

        $windowStart = $asOf->copy()->subDays(self::TURNOVER_WINDOW_DAYS - 1)->toDateString();
        $sold = DB::table('sales_lines')->where('op_type', 'sale')->whereBetween('date', [$windowStart, $date])
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty) AS qty')->pluck('qty', 'product_id');

        $products = DB::table('products as p')
            ->leftJoin('product_groups as g', 'g.id', '=', 'p.product_group_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select('p.id', 'p.code', 'p.name', 'p.volume_l', 'p.sale_price', 'p.series', 'p.cost_estimated', 'p.min_stock_qty',
                'p.product_group_id', 'g.name as group_name', 'c.name as category_name');
        if ($filters->productId !== null) {
            $products->where('p.id', $filters->productId);
        }
        if ($filters->groupId !== null) {
            $products->where('p.product_group_id', $filters->groupId);
        }
        if ($filters->categoryId !== null) {
            $products->where('p.category_id', $filters->categoryId);
        }
        if ($filters->series !== null) {
            $products->where('p.series', $filters->series);
        }

        return $products->get()->map(function ($p) use ($qty, $avg, $sold) {
            $q = (float) ($qty[$p->id]->qty ?? 0);
            $cost = (float) ($avg[$p->id] ?? 0);
            $dailySales = (float) ($sold[$p->id] ?? 0) / self::TURNOVER_WINDOW_DAYS;
            $p->qty = $q;
            $p->liters = $q * (float) $p->volume_l;
            $p->avg_cost = $cost;
            $p->value = $q * $cost;
            $p->sale_value = $q * (float) $p->sale_price;
            $p->potential_profit = $p->sale_value - $p->value;
            $p->last_date = $qty[$p->id]->last_date ?? null;
            $p->daily_sales = $dailySales;
            $p->turnover_days = $dailySales > 0 ? $q / $dailySales : null;
            $threshold = $p->min_stock_qty !== null ? (float) $p->min_stock_qty : $dailySales * self::LOW_STOCK_DAYS;
            $p->low_stock = $dailySales > 0 && $q <= $threshold;
            $p->had_movement = isset($qty[$p->id]);

            return $p;
        })->filter(fn ($p) => $p->had_movement || abs($p->qty) > 0.0001)->values();
    }

    /**
     * Mal kartı: tarix üzrə giriş / çıxış / qalıq və orta mayanın dəyişməsi.
     *
     * @return Collection<int, object>
     */
    public function productCard(int $productId, ?string $from = null, ?string $to = null): Collection
    {
        return DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('customers as c', 'c.id', '=', 'm.customer_id')
            ->where('m.product_id', $productId)
            ->when($from, fn ($q) => $q->where('m.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('m.date', '<=', $to))
            ->orderBy('m.seq')
            ->select('m.*', 'w.name as warehouse_name', 'c.name as customer_name')
            ->get();
    }

    public static function typeLabel(string $type): string
    {
        return [
            'opening' => 'Açılış qalığı', 'purchase' => 'İdxal / alış', 'sale' => 'Satış', 'gift' => 'Hədiyyə / pulsuz',
            'internal_use' => 'Daxili istifadə', 'return' => 'Qaytarma', 'transfer_out' => 'Transfer (çıxış)',
            'transfer_in' => 'Transfer (giriş)', 'adjustment' => 'Sayım düzəlişi',
        ][$type] ?? $type;
    }
}
