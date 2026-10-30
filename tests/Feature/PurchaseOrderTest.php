<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Satınalma təklifi → alış sifarişi → sifarişdən mədaxil (qismən), qalığın bağlanması.
 */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestion_order_and_receipts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        $oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Yağ 4L', 'vat_mode' => 'exempt', 'vat_rate' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $supplier = Supplier::query()->create(['code' => 'T1', 'name' => 'Valvoline Europe', 'currency' => 'USD', 'lead_days' => 10]);
        $other = Supplier::query()->create(['code' => 'T2', 'name' => 'Başqa', 'currency' => 'USD']);
        // 200 alınıb, son 90 gündə 180 satılıb → gündə 2
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subDays(120)->toDateString(), 'period' => now()->subDays(120)->format('Y-m'), 'product_id' => $oil,
            'product_code' => 'OIL', 'qty' => 200, 'unit_cost' => 8, 'total_cost' => 1600, 'row_no' => 1, 'warehouse_id' => $wh, 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(0, 89) as $d) {
            DB::table('sales_lines')->insert(['row_no' => 1, 'date' => now()->subDays($d)->toDateString(), 'period' => now()->subDays($d)->format('Y-m'), 'order_no' => 'S'.$d,
                'product_id' => $oil, 'product_code' => 'OIL', 'qty' => 2, 'net_amount' => 30, 'op_type' => 'sale', 'created_at' => now(), 'updated_at' => now()]);
        }
        // son təchizatçı: 10 ədəd mədaxil (stok 30)
        $this->post(route('purchase-docs.store'), ['type' => 'receipt', 'date' => now()->toDateString(), 'supplier_id' => $supplier->id, 'warehouse_id' => $wh, 'rate' => 1.7,
            'lines' => [['product_id' => $oil, 'qty' => 10, 'price' => 5]]])->assertSessionHas('popup');
        app(AnalyticsRebuilder::class)->run();

        // ehtiyat 14 × 2 = 28; sifariş nöqtəsi 2 × 10 + 28 = 48 > 30 → təklif 2 × (10 + 30) + 28 − 30 = 78
        $row = app(PurchaseOrderService::class)->suggestions($supplier, 30, false)->sole();
        $this->assertEquals([30, 2, 15, 78, 5], [$row->stock, $row->daily, $row->days_left, $row->suggested, $row->price]);
        $page = $this->get(route('purchase-orders.suggest', ['supplier' => $supplier->id]))->assertOk()->assertSee('Yağ 4L')->assertSee('Alış sifarişi yarat');
        $this->assertEquals(78, $page->viewData('rows')->sole()->suggested);
        $this->assertCount(0, app(PurchaseOrderService::class)->suggestions($other, 30, false), 'malın son təchizatçısı başqadır');

        $this->post(route('purchase-orders.store'), ['supplier_id' => $supplier->id, 'warehouse_id' => $wh, 'date' => now()->toDateString(),
            'lines' => [['product_id' => $oil, 'qty' => 78, 'price' => 5]]])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'AS-000001');
        $order = PurchaseOrder::query()->sole();
        $this->assertEquals([390, 'USD', now()->addDays(10)->toDateString()], [(float) $order->total_amount, $order->currency, $order->expected_date->toDateString()]);
        $this->assertSame([$oil => 78.0], app(PurchaseOrderService::class)->onOrder());
        $this->assertCount(0, app(PurchaseOrderService::class)->suggestions($supplier, 30, false), 'yoldakı sifariş təklifi bağlayır');
        $this->get(route('purchase-orders.show', [$order, 'print' => 1]))->assertOk()->assertSee('Alış sifarişi № AS-000001');

        // sifarişdən mədaxil: forma qalıqla dolur; başqa təchizatçıya bağlamaq olmaz
        $form = $this->get(route('purchase-docs.create', ['type' => 'receipt', 'order' => $order->id]))->assertOk()->assertSee('AS-000001');
        $this->assertSame([['product_id' => $oil, 'qty' => 78.0, 'price' => 5.0]], $form->viewData('config')['lines']);
        $this->assertSame($supplier->id, $form->viewData('config')['supplier']);
        $receipt = ['type' => 'receipt', 'date' => now()->toDateString(), 'warehouse_id' => $wh, 'rate' => 1.7, 'purchase_order_id' => $order->id];
        $this->post(route('purchase-docs.store'), $receipt + ['supplier_id' => $other->id, 'lines' => [['product_id' => $oil, 'qty' => 50, 'price' => 5]]])->assertSessionHasErrors('purchase_order_id');
        $this->post(route('purchase-docs.store'), $receipt + ['supplier_id' => $supplier->id, 'lines' => [['product_id' => $oil, 'qty' => 50, 'price' => 5]]])->assertSessionHas('popup');
        $this->assertSame('partial', app(PurchaseOrderService::class)->status($order->fresh()));
        $this->assertSame([['product_id' => $oil, 'qty' => 28.0, 'price' => 5.0]], app(PurchaseOrderService::class)->remainingLines($order));
        $this->get(route('purchase-orders.show', $order))->assertSee('Qismən qəbul edilib')->assertSee('MD-000002');
        $this->delete(route('purchase-orders.destroy', $order), ['reason' => 'x'])->assertSessionHas('error');

        // qalıq bağlanır → yolda heç nə yoxdur; yenidən açılır və son 28 qəbul olunur
        $this->post(route('purchase-orders.close', $order));
        $this->assertSame(['closed', []], [app(PurchaseOrderService::class)->status($order->fresh()), app(PurchaseOrderService::class)->onOrder()]);
        $this->post(route('purchase-orders.close', $order));
        $this->post(route('purchase-docs.store'), $receipt + ['supplier_id' => $supplier->id, 'lines' => [['product_id' => $oil, 'qty' => 28, 'price' => 5]]])->assertSessionHas('popup');
        $this->assertSame('received', app(PurchaseOrderService::class)->status($order->fresh()));
        $this->get(route('purchase-orders.index'))->assertOk()->assertSee('AS-000001')->assertSee('Tam qəbul edilib');

        $this->actingAs(User::factory()->create(['role' => 'menecer']))->get(route('purchase-orders.suggest'))->assertForbidden();
    }
}
