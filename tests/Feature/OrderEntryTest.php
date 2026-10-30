<?php

namespace Tests\Feature;

use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use App\Services\SalesDocumentService;
use App\Support\Fmt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $managerId;

    private int $otherManagerId;

    private int $customerId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = now();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->managerId = DB::table('managers')->insertGetId(['name' => 'Əkbər', 'created_at' => $now, 'updated_at' => $now]);
        $this->otherManagerId = DB::table('managers')->insertGetId(['name' => 'Rasim', 'created_at' => $now, 'updated_at' => $now]);
        $this->customerId = DB::table('customers')->insertGetId(['code' => 'C-001', 'name' => 'Turabov Rəsul', 'manager_id' => $this->managerId, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('customers')->insert(['code' => 'C-002', 'name' => 'Başqa Müştəri', 'manager_id' => $this->otherManagerId, 'created_at' => $now, 'updated_at' => $now]);
        $this->productId = DB::table('products')->insertGetId(['code' => 'VE11258', 'name' => 'Valvoline MaxLife 5W-40 4L', 'sale_price' => 50, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[1, '2026-01-10', 10, 20.0], [2, '2026-02-10', 10, 30.0]] as $i => [$batch, $date, $qty, $cost]) {
            DB::table('purchase_lines')->insert([
                'batch_no' => $batch, 'date' => $date, 'period' => substr($date, 0, 7), 'product_id' => $this->productId, 'product_code' => 'VE11258',
                'qty' => $qty, 'unit_cost' => $cost, 'total_cost' => $qty * $cost, 'row_no' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('sales_orders')->insert(['order_no' => '3930', 'date' => '2026-03-01', 'period' => '2026-03', 'created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-03-05',
            'order_no' => '3931',
            'invoice_no' => 'Q-77',
            'customer_id' => $this->customerId,
            'manager_id' => $this->managerId,
            'warehouse_id' => Warehouse::query()->where('code', 'A2')->value('id'),
            'note' => 'Çatdırılma cümə günü',
            'lines' => [
                ['product_id' => $this->productId, 'op_type' => 'sale', 'qty' => 12, 'price' => 50, 'discount' => 10],
                ['product_id' => $this->productId, 'op_type' => 'gift', 'qty' => 1, 'price' => 0, 'discount' => 0],
            ],
        ], $overrides);
    }

    public function test_form_suggests_next_number_and_lists_customers_with_their_manager(): void
    {
        $this->actingAs($this->admin)->get(route('orders.index'))->assertOk()->assertSee(route('orders.create'), false);
        $this->get(route('orders.create'))->assertOk()
            ->assertSee('Sifariş detalları')->assertSee('Satış nümayəndəsi')->assertSee('Cənub bölgəsi — Anbar 2')
            ->assertSee('value="3931"', false)
            ->assertViewHas('customers', fn ($c) => $c->firstWhere('code', 'C-001')['manager_id'] === $this->managerId)
            ->assertViewHas('products', fn ($p) => $p->first()['price'] === 50.0);
    }

    /**
     * Sifariş → yalnız rezerv; «Sifarişi göndər» → TT (real stok); «Fakturala» → SF (borc və gəlir).
     */
    public function test_order_reserves_delivery_reduces_stock_and_invoice_creates_debt(): void
    {
        $a2 = Warehouse::query()->where('code', 'A2')->value('id');
        $response = $this->actingAs($this->admin)->post(route('orders.store'), $this->payload());
        $order = SalesOrder::query()->where('order_no', '3931')->firstOrFail();
        $response->assertRedirect(route('orders.show', $order))->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === '3931');

        $this->assertSame(['manual', 'open', 'Çatdırılma cümə günü'], [$order->source, $order->status, $order->note]);
        $this->assertEqualsWithDelta(540.0, (float) $order->total_amount, 0.001, '12 × 50 − 10 %; hədiyyə məbləğə düşmür');
        $items = DB::table('sales_order_items')->where('sales_order_id', $order->id)->orderBy('row_no')->get();
        $this->assertCount(2, $items);
        $this->assertEqualsWithDelta(0.1, (float) $items[0]->discount_pct, 0.0001);
        $this->assertSame(0, DB::table('sales_lines')->where('sales_order_id', $order->id)->count(), 'sifariş satış deyil');

        app(AnalyticsRebuilder::class)->run();
        $this->assertNull(DB::table('stock_balances')->where('product_id', $this->productId)->where('warehouse_id', $a2)->value('qty'), 'real stok dəyişmir');
        $this->assertSame([$this->productId => [$a2 => 13.0]], app(SalesDocumentService::class)->reserved());
        $this->assertEqualsWithDelta(0.0, (float) DB::table('customer_balances')->where('customer_id', $order->customer_id)->value('sales_total'), 0.001, 'borca təsir etmir');
        $this->get(route('stock.index', ['only' => 'all']))->assertOk()->assertSee('Rezerv stok');
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Rezervdə')->assertSee('Cənub bölgəsi — Anbar 2')->assertSee(route('orders.send', $order), false);

        // göndər → yalnız TT
        $this->post(route('orders.send', $order))->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('popup', fn ($p) => count($p['docs']) === 1 && $p['docs'][0]['no'] === 'TT-000001');
        $this->assertSame('shipped', $order->refresh()->status);
        $this->assertSame(0, DB::table('sales_invoices')->count());
        app(AnalyticsRebuilder::class)->run();
        $this->assertSame([], app(SalesDocumentService::class)->reserved(), 'rezerv azad olunur');
        $this->assertEqualsWithDelta(-13.0, (float) DB::table('stock_balances')->where('product_id', $this->productId)->where('warehouse_id', $a2)->value('qty'), 0.001);
        // FIFO: 10 × 20 + 3 × 30 = 290 (12 satış + 1 hədiyyə)
        $this->assertEqualsWithDelta(290.0, (float) DB::table('delivery_notes')->value('total_cogs'), 0.01);
        $order->refresh();
        $this->assertEqualsWithDelta(290.0, (float) $order->total_cogs, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $order->gross_profit, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) DB::table('customer_balances')->where('customer_id', $order->customer_id)->value('sales_total'), 0.001);
        $this->get(route('orders.edit', $order))->assertForbidden();

        // fakturala → yalnız SF (TT artıq var)
        $this->post(route('orders.invoice', $order))->assertSessionHas('popup', fn ($p) => array_column($p['docs'], 'no') === ['SF-000001']);
        $this->assertSame('invoiced', $order->refresh()->status);
        app(AnalyticsRebuilder::class)->run();
        $lines = DB::table('sales_lines')->where('sales_order_id', $order->id)->orderBy('row_no')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('SF-000001', $lines[0]->invoice_no);
        $this->assertEqualsWithDelta(290.0, $lines->sum('cogs'), 0.01, 'maya TT-dən gəlir');
        $this->assertEqualsWithDelta(-13.0, (float) DB::table('stock_balances')->where('product_id', $this->productId)->where('warehouse_id', $a2)->value('qty'), 0.001, 'faktura stoku ikinci dəfə azaltmır');
        $this->assertEqualsWithDelta(540.0, (float) DB::table('customer_balances')->where('customer_id', $order->customer_id)->value('sales_total'), 0.001, 'faktura borca yazılır');
        $this->assertEqualsWithDelta(250.0, (float) $order->refresh()->gross_profit, 0.01);

        $delivery = DB::table('delivery_notes')->value('id');
        $invoice = DB::table('sales_invoices')->value('id');
        $this->get(route('deliveries.index', ['preset' => 'all']))->assertOk()->assertSee('TT-000001')->assertSee('SF-000001');
        $this->get(route('invoices.index', ['preset' => 'all']))->assertOk()->assertSee('SF-000001')->assertSee('Turabov Rəsul');
        $this->get(route('deliveries.show', $delivery))->assertOk()->assertSee('VE11258')->assertSee('3931');
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee(Fmt::money(540), false);
        $this->get(route('invoices.show', [$invoice, 'print' => 1]))->assertOk()->assertSee('Satış fakturası № SF-000001')->assertSee('Alıcı');
        $this->get(route('deliveries.show', [$delivery, 'print' => 1]))->assertOk()->assertSee('Təhvil-təslim aktı № TT-000001')->assertSee('Təhvil aldı');
        $this->get(route('orders.print', $order))->assertOk()->assertSee('Sifariş № 3931');

        // ləğv: TT fakturası olduğu üçün ləğv olunmur; əvvəl faktura
        $this->delete(route('deliveries.destroy', $delivery))->assertSessionHas('error');
        $this->delete(route('invoices.destroy', $invoice), ['reason' => 'Səhv qiymət'])->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame(['shipped', 0], [$order->refresh()->status, DB::table('sales_lines')->where('sales_order_id', $order->id)->count()]);
        // faktura silinmir: nömrəsi, sətirlərinin surəti və səbəbi qalır
        $this->assertSame('Səhv qiymət', DB::table('sales_invoices')->where('id', $invoice)->whereNotNull('cancelled_at')->value('cancel_reason'));
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Bu sənəd ləğv edilib')->assertSee('Səhv qiymət')->assertSee('MaxLife');
        $this->get(route('invoices.index'))->assertDontSee('SF-000001');
        $this->get(route('invoices.index', ['state' => 'cancelled']))->assertSee('SF-000001');
        $this->delete(route('invoices.destroy', $invoice))->assertSessionHas('error');

        $this->delete(route('deliveries.destroy', $delivery))->assertRedirect(route('deliveries.show', $delivery));
        $this->assertSame('open', $order->refresh()->status);
        $this->assertNotNull(DB::table('delivery_notes')->where('id', $delivery)->value('cancelled_at'));
        // yenidən fakturalananda yeni nömrələr verilir (ləğv edilmiş nömrə təkrar istifadə olunmur)
        $this->post(route('orders.invoice', $order))->assertSessionHas('popup', fn ($p) => collect($p['docs'])->pluck('no')->all() === ['TT-000002', 'SF-000002']);
    }

    public function test_save_and_invoice_creates_both_documents_at_once(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), $this->payload(['then' => 'invoice']))
            ->assertSessionHas('popup', fn ($p) => array_column($p['docs'], 'no') === ['3931', 'TT-000001', 'SF-000001']);
        $this->assertSame('invoiced', SalesOrder::query()->where('order_no', '3931')->value('status'));
        $this->post(route('orders.store'), $this->payload(['order_no' => '3932', 'then' => 'send']))
            ->assertSessionHas('popup', fn ($p) => array_column($p['docs'], 'type') === ['Sifariş', 'Təhvil-təslim sənədi']);
        $this->assertSame(['shipped', 1], [SalesOrder::query()->where('order_no', '3932')->value('status'), DB::table('sales_invoices')->count()]);
        $this->get(route('orders.index', ['preset' => 'all']))->assertSee('Fakturalanıb')->assertSee('Təhvil verilib');
    }

    public function test_manual_order_can_be_edited_and_deleted_but_imported_cannot(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), $this->payload());
        $order = SalesOrder::query()->where('order_no', '3931')->firstOrFail();

        $this->get(route('orders.edit', $order))->assertOk()->assertSee('VE11258');
        $this->put(route('orders.update', $order), $this->payload(['lines' => [['product_id' => $this->productId, 'op_type' => 'sale', 'qty' => 2, 'price' => 60]]]))
            ->assertRedirect(route('orders.show', $order));
        $this->assertSame(1, DB::table('sales_order_items')->where('sales_order_id', $order->id)->count());
        $this->assertEqualsWithDelta(120.0, (float) $order->refresh()->total_amount, 0.001);

        $imported = SalesOrder::query()->where('order_no', '3930')->firstOrFail();
        $this->get(route('orders.edit', $imported))->assertForbidden();
        $this->delete(route('orders.destroy', $imported))->assertForbidden();

        $this->delete(route('orders.destroy', $order), ['reason' => 'Müştəri imtina etdi'])->assertRedirect(route('orders.show', $order));
        $this->assertSame(['cancelled', 'Müştəri imtina etdi'], [$order->refresh()->status, $order->cancel_reason]);
        $this->assertSame([], app(SalesDocumentService::class)->reserved());
        $this->get(route('orders.edit', $order))->assertForbidden();
        $this->post(route('orders.send', $order))->assertForbidden();
    }

    public function test_validation_and_manager_restriction(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), $this->payload(['order_no' => '3930', 'lines' => []]))
            ->assertSessionHasErrors(['order_no', 'lines']);

        $manager = User::factory()->create(['role' => 'menecer', 'manager_id' => $this->managerId]);
        $other = DB::table('customers')->where('code', 'C-002')->value('id');
        $this->actingAs($manager)->get(route('orders.create'))->assertOk()->assertViewHas('customers', fn ($c) => $c->pluck('code')->all() === ['C-001']);
        $this->post(route('orders.store'), $this->payload(['customer_id' => $other]))->assertSessionHasErrors('customer_id');
        // menecer başqa nümayəndə seçsə belə sifariş öz adına yazılır
        $this->post(route('orders.store'), $this->payload(['manager_id' => $this->otherManagerId]))->assertRedirect();
        $this->assertSame($this->managerId, SalesOrder::query()->where('order_no', '3931')->value('manager_id'));
    }
}
