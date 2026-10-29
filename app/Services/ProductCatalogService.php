<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Məhsullar siyahısı üçün göstəricilər: real / rezerv / sərbəst stok, min–max, qiymətlər, marja,
 * son 30 gün satış, dövriyyə günləri, son satış / alış tarixi və ABC sinfi (son 90 günün gəliri üzrə: A 80 %, B 15 %, C 5 %).
 */
class ProductCatalogService
{
    public const ABC_DAYS = 90;

    public function __construct(private PriceService $prices, private SalesDocumentService $documents) {}

    /**
     * @param  array{warehouse_id?: int|null}  $options
     * @return Collection<int, object>
     */
    public function rows(array $options = []): Collection
    {
        $warehouseId = $options['warehouse_id'] ?? null;
        $today = now()->startOfDay();

        $stock = DB::table('stock_balances')->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty) AS qty')->pluck('qty', 'product_id');
        $reserved = [];
        foreach ($this->documents->reserved() as $productId => $byWarehouse) {
            $reserved[$productId] = $warehouseId ? ($byWarehouse[$warehouseId] ?? 0.0) : array_sum($byWarehouse);
        }
        $from30 = $today->copy()->subDays(29)->toDateString();
        $from90 = $today->copy()->subDays(self::ABC_DAYS - 1)->toDateString();
        $sales = DB::table('sales_lines')->whereNotNull('product_id')->where('op_type', 'sale')->where('date', '>=', $from90)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(CASE WHEN date >= ? THEN qty ELSE 0 END) AS qty30, SUM(net_amount) AS revenue90', [$from30])
            ->get()->keyBy('product_id');
        $lastSale = DB::table('sales_lines')->whereNotNull('product_id')->where('op_type', 'sale')->groupBy('product_id')->selectRaw('product_id, MAX(date) AS d')->pluck('d', 'product_id');
        $lastPurchase = DB::table('purchase_lines')->whereNotNull('product_id')->groupBy('product_id')->selectRaw('product_id, MAX(date) AS d')->pluck('d', 'product_id');
        $salePrices = $this->prices->salePrices(null, $today);
        $purchasePrices = $this->prices->purchasePrices($today);
        $barcodes = DB::table('product_barcodes')->get(['product_id', 'barcode'])->groupBy('product_id')->map(fn ($r) => $r->pluck('barcode')->implode(' '));
        $customPrices = DB::table('price_cards')->whereNotNull('customer_id')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today->toDateString()))
            ->groupBy('product_id')->selectRaw('product_id, COUNT(*) AS n')->pluck('n', 'product_id');

        $rows = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('manufacturers as mf', 'mf.id', '=', 'p.manufacturer_id')
            ->leftJoin('product_classes as pc', 'pc.id', '=', 'p.product_class_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_groups as g', 'g.id', '=', 'p.product_group_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->get(['p.id', 'p.code', 'p.name', 'p.barcode', 'p.series', 'p.volume_l', 'p.is_active', 'p.min_stock_qty', 'p.max_stock_qty',
                'p.current_avg_cost', 'p.cost_estimated', 'p.vat_rate', 'p.vat_mode', 'p.brand_id', 'p.product_class_id', 'p.category_id', 'p.manufacturer_id', 'p.source',
                'b.name as brand_name', 'mf.name as manufacturer_name', 'pc.name as class_name', 'c.name as category_name', 'g.name as group_name', 'u.name as unit_name'])
            ->map(function ($p) use ($stock, $reserved, $sales, $lastSale, $lastPurchase, $salePrices, $purchasePrices, $barcodes, $customPrices) {
                $p->is_active = (bool) $p->is_active;
                $p->qty = round((float) ($stock[$p->id] ?? 0), 4);
                $p->reserved = round((float) ($reserved[$p->id] ?? 0), 4);
                $p->available = round($p->qty - $p->reserved, 4);
                $s = $sales[$p->id] ?? null;
                $p->sold30 = round((float) ($s->qty30 ?? 0), 4);
                $p->revenue90 = (float) ($s->revenue90 ?? 0);
                $daily = $p->sold30 / 30;
                $p->turnover_days = $daily > 0 && $p->qty > 0 ? (int) round($p->qty / $daily) : null;
                // min həddi yoxdursa — 14 günlük satış
                $p->min_effective = $p->min_stock_qty !== null ? (float) $p->min_stock_qty : ($daily > 0 ? round($daily * 14, 2) : null);
                $p->sale_price = $salePrices[$p->id]['price'] ?? null;
                $p->purchase_price = $purchasePrices[$p->id]['price'] ?? null;
                $p->avg_cost = $p->current_avg_cost !== null ? (float) $p->current_avg_cost : null;
                $cost = $p->avg_cost ?? $p->purchase_price;
                $p->margin = $p->sale_price && $cost !== null ? ($p->sale_price - $cost) / $p->sale_price : null;
                $p->stock_value = $cost !== null ? round(max($p->qty, 0) * $cost, 2) : null;
                $p->last_sale = $lastSale[$p->id] ?? null;
                $p->last_purchase = $lastPurchase[$p->id] ?? null;
                $p->barcodes = trim(($p->barcode ?? '').' '.($barcodes[$p->id] ?? ''));
                $p->custom_prices = (int) ($customPrices[$p->id] ?? 0);
                $p->state = match (true) {
                    ! $p->is_active => 'passive',
                    $p->qty < -0.0001 => 'negative',
                    abs($p->qty) <= 0.0001 => 'out',
                    $p->min_effective !== null && $p->available < $p->min_effective => 'low',
                    $p->max_stock_qty !== null && $p->qty > (float) $p->max_stock_qty => 'over',
                    $p->last_sale === null || Carbon::parse($p->last_sale)->lt(now()->subDays(180)) => 'dead',
                    default => 'ok',
                };
                // tövsiyə olunan sifariş: max (yoxdursa 2 × min) səviyyəsinə qədər
                $target = $p->max_stock_qty !== null ? (float) $p->max_stock_qty : ($p->min_effective !== null ? $p->min_effective * 2 : null);
                $p->reorder_qty = $target !== null && $p->min_effective !== null && $p->available < $p->min_effective ? round($target - $p->available, 2) : null;

                return $p;
            });

        $this->assignAbc($rows);

        return $rows;
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function assignAbc(Collection $rows): void
    {
        $total = $rows->sum('revenue90');
        $running = 0.0;
        foreach ($rows->sortByDesc('revenue90') as $row) {
            if ($row->revenue90 <= 0 || $total <= 0) {
                $row->abc = null;

                continue;
            }
            $running += $row->revenue90;
            $share = $running / $total;
            $row->abc = $share <= 0.8 + 1e-9 ? 'A' : ($share <= 0.95 + 1e-9 ? 'B' : 'C');
        }
    }

    /**
     * @return array<string, string>
     */
    public static function stateLabels(): array
    {
        return [
            'ok' => __('Normal'), 'low' => __('Az qalıb'), 'out' => __('Bitib'), 'negative' => __('Mənfi qalıq'),
            'over' => __('Max həddindən çox'), 'dead' => __('Hərəkətsiz (180 gün)'), 'passive' => __('Passiv'),
        ];
    }
}
