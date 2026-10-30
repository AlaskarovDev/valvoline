<?php

namespace App\Services;

use App\Models\Warehouse;
use App\Support\BulkUpdater;
use Illuminate\Support\Facades\DB;

/**
 * Maya dəyəri, mal kodu üzrə — FIFO (standart) və ya hərəkətli orta (config app.costing_method = fifo | average).
 *
 * FIFO: hər alış ayrıca partiya (qat) kimi saxlanılır; satış / hədiyyə / daxili istifadə ən köhnə partiyalardan çıxır.
 * Qaytarma və artıq sayım sonuncu çıxışın mayası ilə yeni partiya kimi girir. Anbar dəyəri = qalan partiyaların cəmi;
 * stock_movements.running_avg_cost və products.current_avg_cost qalan partiyaların orta mayasıdır.
 *
 * Hərəkətli orta:
 * Bütün hərəkətlər qlobal olaraq tarixə görə sıralanır; eyni gündə sıra:
 * alış/açılış (0) → qaytarma (1) → transfer (2) → satış/hədiyyə/daxili (3) → sayım düzəlişi (4).
 *
 * - Alış: yeni_orta = (köhnə_qalıq × köhnə_orta + alış × alış_mayası) / (köhnə_qalıq + alış); köhnə_qalıq < 0 → 0.
 * - Satış / hədiyyə / daxili istifadə və qaytarma həmin anın orta mayası ilə çıxır / girir.
 * - Mayası hələ yoxdursa: eyni Mehsul_Qrupu-nun son orta mayası → o da yoxdursa malın ilk alış mayası.
 * - Transferlər mayaya təsir etmir, yalnız anbar yerini dəyişir.
 *
 * Nəticə: stock_movements jurnalı, sales_lines/sales_returns-da unit_cost, cogs və products.current_avg_cost.
 */
class CostingService
{
    private const PRIORITY = [
        'opening' => 0, 'purchase' => 0, 'return' => 1, 'transfer_out' => 2, 'transfer_in' => 2,
        'sale' => 3, 'gift' => 3, 'internal_use' => 3, 'adjustment' => 4,
    ];

    /** @var array<int, float> */
    private array $qty = [];

    /** @var array<int, float|null> */
    private array $avg = [];

    /** @var array<int, array<int, float>> */
    private array $warehouseQty = [];

    /** @var array<int, float> */
    private array $groupLastAvg = [];

    /** @var array<int, int|null> */
    private array $productGroup = [];

    /** @var array<int, float> */
    private array $firstPurchaseCost = [];

    /** @var array<int, list<array{0: float, 1: float}>> FIFO partiyaları: mal => [[miqdar, maya], …] (ən köhnə əvvəldə) */
    private array $layers = [];

    /** @var array<int, float> FIFO: malın sonuncu çıxış mayası (qaytarma bu maya ilə girir) */
    private array $lastIssueCost = [];

    private string $method = 'fifo';

    public static function method(): string
    {
        return config('app.costing_method') === 'average' ? 'average' : 'fifo';
    }

    /**
     * İstifadəçiyə göstərilən üsul adı: «FIFO» və ya «hərəkətli orta».
     */
    public static function methodLabel(): string
    {
        return self::method() === 'fifo' ? 'FIFO' : __('hərəkətli orta');
    }

    /**
     * @param  callable(string, float): void|null  $progress
     * @return array{movements: int, fallbacks: int, negative_products: int}
     */
    public function run(?callable $progress = null): array
    {
        $progress ??= fn () => null;
        $this->reset();
        $mainWarehouse = (int) Warehouse::query()->where('is_main', true)->value('id');

        $progress(__('Hərəkətlər toplanır'), 0.05);
        $events = $this->collectEvents($mainWarehouse);
        usort($events, fn ($a, $b) => [$a['date'], $a['priority'], $a['source_order'], $a['source_id'], $a['leg']]
            <=> [$b['date'], $b['priority'], $b['source_order'], $b['source_id'], $b['leg']]);

        $progress($this->method === 'fifo' ? __('FIFO maya hesablanır') : __('Orta maya hesablanır'), 0.25);
        $movements = [];
        $salesCosts = [];
        $deliveryCosts = [];
        $returnCosts = [];
        $fallbacks = 0;
        $seq = 0;
        $lastDate = [];
        foreach ($events as $event) {
            $productId = $event['product_id'];
            $warehouseId = $event['warehouse_id'];
            $qtyBefore = $this->qty[$productId] ?? 0.0;
            $fallback = false;

            if ($this->method === 'fifo') {
                [$unitCost, $fallback] = $this->fifo($event, $qtyBefore);
                $fallbacks += $fallback ? 1 : 0;
            } elseif ($event['type'] === 'purchase' || $event['type'] === 'opening') {
                $unitCost = (float) $event['unit_cost'];
                if ($event['qty'] > 0) {
                    $baseQty = max($qtyBefore, 0.0);
                    $baseAvg = $this->avg[$productId] ?? 0.0;
                    $newQty = $baseQty + $event['qty'];
                    $this->avg[$productId] = $newQty > 0 ? ($baseQty * $baseAvg + $event['qty'] * $unitCost) / $newQty : $unitCost;
                }
            } elseif ($event['type'] === 'transfer_out' || $event['type'] === 'transfer_in') {
                $unitCost = $this->avg[$productId] ?? 0.0;
            } else {
                if (($this->avg[$productId] ?? null) === null) {
                    $this->avg[$productId] = $this->fallbackCost($productId);
                    $fallback = true;
                    $fallbacks++;
                }
                $unitCost = (float) $this->avg[$productId];
            }

            $this->qty[$productId] = $qtyBefore + $event['qty'];
            $this->warehouseQty[$productId][$warehouseId] = ($this->warehouseQty[$productId][$warehouseId] ?? 0.0) + $event['qty'];
            if (($group = $this->productGroup[$productId] ?? null) !== null && $this->avg[$productId] !== null) {
                $this->groupLastAvg[$group] = $this->avg[$productId];
            }
            $lastDate[$productId][$warehouseId] = $event['date'];

            $movements[] = [
                'date' => $event['date'],
                'period' => substr($event['date'], 0, 7),
                'seq' => ++$seq,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'type' => $event['type'],
                'qty' => $event['qty'],
                'unit_cost' => round($unitCost, 4),
                'total_cost' => round($event['qty'] * $unitCost, 4),
                'running_qty' => round($this->qty[$productId], 4),
                'running_wh_qty' => round($this->warehouseQty[$productId][$warehouseId], 4),
                'running_avg_cost' => round($this->avg[$productId] ?? 0.0, 4),
                'source_type' => $event['source_type'],
                'source_id' => $event['source_id'],
                'customer_id' => $event['customer_id'],
                'doc_no' => $event['doc_no'],
            ];

            if ($event['source_type'] === 'sales_line') {
                $salesCosts[$event['source_id']] = ['unit_cost' => $unitCost, 'fallback' => $fallback];
            } elseif ($event['source_type'] === 'delivery_line') {
                $deliveryCosts[$event['source_id']] = ['unit_cost' => $unitCost, 'fallback' => $fallback];
            } elseif ($event['source_type'] === 'sales_return') {
                $returnCosts[$event['source_id']] = $unitCost;
            }
        }

        $progress(__('Anbar jurnalı yazılır'), 0.45);
        DB::table('stock_movements')->delete();
        foreach (array_chunk($movements, 500) as $chunk) {
            DB::table('stock_movements')->insert($chunk);
        }

        $progress(__('Satış sətirlərinin mayası yazılır'), 0.6);
        $salesCosts += $this->writeDeliveryCosts($deliveryCosts);
        $this->writeSalesCosts($salesCosts);
        $this->writeReturnCosts($returnCosts);

        $progress(__('Anbar qalıqları yazılır'), 0.7);
        $this->writeProductState($lastDate);

        $negative = count(array_filter($this->qty, fn ($q) => $q < -0.0001));

        return ['movements' => count($movements), 'fallbacks' => $fallbacks, 'negative_products' => $negative];
    }

    /**
     * FIFO üzrə bir hərəkət: partiyaları yeniləyir, hərəkətin vahid mayasını qaytarır.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: float, 1: bool} [vahid maya, ehtiyat maya istifadə olunub]
     */
    private function fifo(array $event, float $qtyBefore): array
    {
        $productId = $event['product_id'];
        $qty = (float) $event['qty'];
        $fallback = false;

        if ($event['type'] === 'transfer_out' || $event['type'] === 'transfer_in') {
            return [$this->avg[$productId] ?? 0.0, false];
        }
        if ($qty > 0) {
            // giriş: alış öz mayası ilə; qaytarma / artıq sayım — sonuncu çıxış mayası ilə
            $unitCost = in_array($event['type'], ['purchase', 'opening'], true)
                ? (float) $event['unit_cost']
                : ($this->lastIssueCost[$productId] ?? $this->avg[$productId] ?? null);
            if ($unitCost === null) {
                $unitCost = $this->fallbackCost($productId);
                $fallback = true;
            }
            // mənfi qalıq varsa (mayasız çıxılmış miqdar) giriş əvvəlcə onu bağlayır
            $layerQty = $qty - max(0.0, -$qtyBefore);
            if ($layerQty > 0.0000001) {
                $this->layers[$productId][] = [$layerQty, $unitCost];
            }
        } else {
            $need = -$qty;
            $cost = 0.0;
            while ($need > 0.0000001 && ! empty($this->layers[$productId])) {
                $take = min($need, $this->layers[$productId][0][0]);
                $cost += $take * $this->layers[$productId][0][1];
                $this->layers[$productId][0][0] -= $take;
                $need -= $take;
                if ($this->layers[$productId][0][0] <= 0.0000001) {
                    array_shift($this->layers[$productId]);
                }
            }
            if ($need > 0.0000001) {
                // partiya çatmır: son məlum maya, o da yoxdursa qrupun / ilk alışın mayası
                $known = $this->avg[$productId] ?? null;
                if ($known === null) {
                    $known = $this->fallbackCost($productId);
                    $fallback = $cost < 0.0000001;
                }
                $cost += $need * $known;
            }
            $unitCost = $qty < 0 ? $cost / -$qty : 0.0;
            $this->lastIssueCost[$productId] = $unitCost;
        }

        // qalan partiyaların orta mayası (anbar dəyəri üçün); partiya qalmayıbsa son məlum maya
        $layerQty = array_sum(array_column($this->layers[$productId] ?? [], 0));
        if ($layerQty > 0.0000001) {
            $this->avg[$productId] = array_sum(array_map(fn ($l) => $l[0] * $l[1], $this->layers[$productId])) / $layerQty;
        } else {
            $this->avg[$productId] ??= $unitCost;
        }

        return [$unitCost, $fallback];
    }

    private function reset(): void
    {
        $this->method = self::method();
        $this->layers = [];
        $this->lastIssueCost = [];
        $this->qty = [];
        $this->avg = [];
        $this->warehouseQty = [];
        $this->groupLastAvg = [];
        $this->productGroup = DB::table('products')->pluck('product_group_id', 'id')->all();
        $this->firstPurchaseCost = [];
        $rows = DB::table('purchase_lines')
            ->whereNotNull('product_id')
            ->where('unit_cost', '>', 0)
            ->orderBy('date')->orderBy('id')
            ->get(['product_id', 'unit_cost']);
        foreach ($rows as $row) {
            $this->firstPurchaseCost[$row->product_id] ??= (float) $row->unit_cost;
        }
    }

    private function fallbackCost(int $productId): float
    {
        $group = $this->productGroup[$productId] ?? null;
        if ($group !== null && isset($this->groupLastAvg[$group])) {
            return $this->groupLastAvg[$group];
        }

        return $this->firstPurchaseCost[$productId] ?? 0.0;
    }

    /**
     * @return list<array{date: string, priority: int, source_order: int, source_id: int, leg: int, product_id: int, warehouse_id: int, type: string, qty: float, unit_cost: float|null, source_type: string, customer_id: int|null, doc_no: string|null}>
     */
    private function collectEvents(int $mainWarehouse): array
    {
        $events = [];
        $push = function (array $event) use (&$events) {
            $event['priority'] = self::PRIORITY[$event['type']];
            $event['leg'] ??= 0;
            $event['customer_id'] ??= null;
            $event['doc_no'] ??= null;
            $event['unit_cost'] ??= null;
            $events[] = $event;
        };

        foreach (DB::table('purchase_lines')->whereNotNull('product_id')->orderBy('id')
            ->get(['id', 'date', 'product_id', 'qty', 'unit_cost', 'batch_no']) as $row) {
            $push([
                'date' => (string) $row->date, 'source_order' => 1, 'source_id' => $row->id, 'product_id' => $row->product_id,
                'warehouse_id' => $mainWarehouse, 'type' => (int) $row->batch_no === 0 ? 'opening' : 'purchase',
                'qty' => (float) $row->qty, 'unit_cost' => (float) $row->unit_cost, 'source_type' => 'purchase_line',
                'doc_no' => 'P-'.$row->batch_no,
            ]);
        }

        foreach (DB::table('sales_returns')->whereNotNull('product_id')->orderBy('id')
            ->get(['id', 'date', 'product_id', 'qty', 'customer_id', 'return_no']) as $row) {
            $push([
                'date' => (string) $row->date, 'source_order' => 2, 'source_id' => $row->id, 'product_id' => $row->product_id,
                'warehouse_id' => $mainWarehouse, 'type' => 'return', 'qty' => abs((float) $row->qty),
                'source_type' => 'sales_return', 'customer_id' => $row->customer_id, 'doc_no' => $row->return_no,
            ]);
        }

        foreach (DB::table('stock_transfers')->whereNotNull('product_id')->orderBy('id')
            ->get(['id', 'date', 'product_id', 'qty', 'from_warehouse_id', 'to_warehouse_id', 'doc_no']) as $row) {
            $qty = abs((float) $row->qty);
            $common = ['date' => (string) $row->date, 'source_order' => 3, 'source_id' => $row->id, 'product_id' => $row->product_id,
                'source_type' => 'stock_transfer', 'doc_no' => $row->doc_no];
            $push($common + ['warehouse_id' => $row->from_warehouse_id ?? $mainWarehouse, 'type' => 'transfer_out', 'qty' => -$qty, 'leg' => 0]);
            $push($common + ['warehouse_id' => $row->to_warehouse_id ?? $mainWarehouse, 'type' => 'transfer_in', 'qty' => $qty, 'leg' => 1]);
        }

        $types = ['sale' => 'sale', 'gift' => 'gift', 'internal' => 'internal_use'];
        // fakturadan yaranan satış sətri stoku azaltmır — çıxış onun təhvil-təslim sətrindədir
        foreach (DB::table('sales_lines')->whereNotNull('product_id')->whereNull('delivery_note_line_id')->orderBy('id')
            ->get(['id', 'date', 'product_id', 'qty', 'op_type', 'customer_id', 'order_no', 'warehouse_id']) as $row) {
            $push([
                'date' => (string) $row->date, 'source_order' => 4, 'source_id' => $row->id, 'product_id' => $row->product_id,
                'warehouse_id' => $row->warehouse_id ?? $mainWarehouse, 'type' => $types[$row->op_type] ?? 'sale', 'qty' => -(float) $row->qty,
                'source_type' => 'sales_line', 'customer_id' => $row->customer_id, 'doc_no' => $row->order_no,
            ]);
        }

        foreach (DB::table('delivery_note_lines as l')->join('delivery_notes as n', 'n.id', '=', 'l.delivery_note_id')->whereNull('n.cancelled_at')->whereNotNull('l.product_id')->orderBy('l.id')
            ->get(['l.id', 'n.date', 'l.product_id', 'l.qty', 'l.op_type', 'n.customer_id', 'n.doc_no', 'n.warehouse_id']) as $row) {
            $push([
                'date' => (string) $row->date, 'source_order' => 4, 'source_id' => $row->id, 'product_id' => $row->product_id,
                'warehouse_id' => $row->warehouse_id ?? $mainWarehouse, 'type' => $types[$row->op_type] ?? 'sale', 'qty' => -(float) $row->qty,
                'source_type' => 'delivery_line', 'customer_id' => $row->customer_id, 'doc_no' => $row->doc_no,
            ]);
        }

        // açılış sənədi: seçilmiş anbara ilkin qalıq öz mayası ilə (FIFO partiyası yaranır)
        foreach (DB::table('stock_document_lines as l')->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('d.type', 'opening')->whereNull('d.cancelled_at')->orderBy('l.id')
            ->get(['l.id', 'd.date', 'l.product_id', 'l.qty', 'l.unit_cost', 'd.warehouse_id', 'd.doc_no']) as $row) {
            $push([
                'date' => (string) $row->date, 'source_order' => 1, 'source_id' => $row->id, 'product_id' => $row->product_id,
                'warehouse_id' => $row->warehouse_id ?? $mainWarehouse, 'type' => 'opening', 'qty' => (float) $row->qty,
                'unit_cost' => (float) $row->unit_cost, 'source_type' => 'stock_document_line', 'doc_no' => $row->doc_no,
            ]);
        }

        foreach (DB::table('stock_adjustments')->whereNotNull('product_id')->orderBy('id')
            ->get(['id', 'date', 'product_id', 'qty', 'warehouse_id']) as $row) {
            $push([
                'date' => (string) $row->date, 'source_order' => 5, 'source_id' => $row->id, 'product_id' => $row->product_id,
                'warehouse_id' => $row->warehouse_id ?? $mainWarehouse, 'type' => 'adjustment', 'qty' => (float) $row->qty,
                'source_type' => 'stock_adjustment',
            ]);
        }

        return $events;
    }

    /**
     * @param  array<int, array{unit_cost: float, fallback: bool}>  $costs
     */
    private function writeSalesCosts(array $costs): void
    {
        DB::table('sales_lines')->whereNull('product_id')->update([
            'unit_cost' => null, 'cogs' => null, 'gross_profit' => null, 'margin_pct' => null, 'cost_fallback' => false,
        ]);
        $updates = [];
        foreach (DB::table('sales_lines')->whereNotNull('product_id')->get(['id', 'qty', 'net_amount', 'op_type']) as $line) {
            $cost = $costs[$line->id] ?? ['unit_cost' => 0.0, 'fallback' => true];
            $cogs = (float) $line->qty * $cost['unit_cost'];
            $revenue = $line->op_type === 'sale' ? (float) $line->net_amount : 0.0;
            $profit = $revenue - $cogs;
            $updates[$line->id] = [
                'unit_cost' => round($cost['unit_cost'], 4),
                'cogs' => round($cogs, 4),
                'gross_profit' => round($profit, 4),
                'margin_pct' => abs($revenue) > 0.00001 ? round($profit / $revenue, 6) : null,
                'cost_fallback' => $cost['fallback'] ? 1 : 0,
            ];
        }
        BulkUpdater::update('sales_lines', $updates);
    }

    /**
     * Təhvil-təslim sətirlərinin mayası; həmin sətirlərdən yaranan faktura sətirləri eyni vahid mayanı alır.
     *
     * @param  array<int, array{unit_cost: float, fallback: bool}>  $costs
     * @return array<int, array{unit_cost: float, fallback: bool}> faktura sətri id => maya
     */
    private function writeDeliveryCosts(array $costs): array
    {
        $updates = [];
        $totals = [];
        // ləğv edilmiş TT-nin mayası ləğv anındakı kimi qalır (tarixçə üçün)
        foreach (DB::table('delivery_note_lines as l')->join('delivery_notes as n', 'n.id', '=', 'l.delivery_note_id')->whereNull('n.cancelled_at')->get(['l.id', 'l.delivery_note_id', 'l.qty']) as $line) {
            $unit = $costs[$line->id]['unit_cost'] ?? 0.0;
            $cogs = round((float) $line->qty * $unit, 4);
            $updates[$line->id] = ['unit_cost' => round($unit, 4), 'cogs' => $cogs];
            $totals[$line->delivery_note_id] = ($totals[$line->delivery_note_id] ?? 0.0) + $cogs;
        }
        BulkUpdater::update('delivery_note_lines', $updates);
        BulkUpdater::update('delivery_notes', array_map(fn ($t) => ['total_cogs' => round($t, 4)], $totals));

        $invoiced = [];
        foreach (DB::table('sales_lines')->whereNotNull('delivery_note_line_id')->pluck('delivery_note_line_id', 'id') as $salesLineId => $deliveryLineId) {
            $invoiced[$salesLineId] = $costs[$deliveryLineId] ?? ['unit_cost' => 0.0, 'fallback' => true];
        }

        return $invoiced;
    }

    /**
     * @param  array<int, float>  $costs
     */
    private function writeReturnCosts(array $costs): void
    {
        DB::table('sales_returns')->whereNull('product_id')->update(['unit_cost' => null, 'cogs' => null]);
        $updates = [];
        foreach (DB::table('sales_returns')->whereNotNull('product_id')->get(['id', 'qty']) as $return) {
            $unit = $costs[$return->id] ?? 0.0;
            $updates[$return->id] = ['unit_cost' => round($unit, 4), 'cogs' => round(abs((float) $return->qty) * $unit, 4)];
        }
        BulkUpdater::update('sales_returns', $updates);
    }

    /**
     * @param  array<int, array<int, string>>  $lastDate
     */
    private function writeProductState(array $lastDate): void
    {
        $estimated = DB::table('purchase_lines')->where('is_estimated', true)->whereNotNull('product_id')->distinct()->pluck('product_id')->flip();
        $updates = [];
        foreach (DB::table('products')->pluck('id') as $productId) {
            $updates[$productId] = [
                'current_avg_cost' => isset($this->avg[$productId]) ? round($this->avg[$productId], 4) : null,
                'stock_qty' => round($this->qty[$productId] ?? 0.0, 4),
                'cost_estimated' => isset($estimated[$productId]) ? 1 : 0,
            ];
        }
        BulkUpdater::update('products', $updates);

        DB::table('stock_balances')->delete();
        $rows = [];
        foreach ($this->warehouseQty as $productId => $warehouses) {
            foreach ($warehouses as $warehouseId => $qty) {
                $rows[] = [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty' => round($qty, 4),
                    'last_movement_date' => $lastDate[$productId][$warehouseId] ?? null,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('stock_balances')->insert($chunk);
        }
    }
}
