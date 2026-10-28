<?php

namespace Tests\Feature;

use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
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

    public function test_order_is_saved_with_lines_and_costed_from_chosen_warehouse(): void
    {
        $response = $this->actingAs($this->admin)->post(route('orders.store'), $this->payload());
        $order = SalesOrder::query()->where('order_no', '3931')->firstOrFail();
        $response->assertRedirect(route('orders.show', $order));

        $this->assertSame('manual', $order->source);
        $this->assertSame('Çatdırılma cümə günü', $order->note);
        $this->assertEqualsWithDelta(540.0, (float) $order->total_amount, 0.001, '12 × 50 − 10 %; hədiyyə məbləğə düşmür');
        $lines = DB::table('sales_lines')->where('sales_order_id', $order->id)->orderBy('row_no')->get();
        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(0.1, (float) $lines[0]->discount_pct, 0.0001);
        $this->assertEqualsWithDelta(45.0, (float) $lines[0]->discount_price, 0.0001);
        $this->assertSame(['Turabov Rəsul', 'Əkbər'], [$lines[0]->customer_name, $lines[0]->manager_name]);

        app(AnalyticsRebuilder::class)->run();
        $order->refresh();
        // FIFO: 10 × 20 + 3 × 30 = 290 (12 satış + 1 hədiyyə)
        $this->assertEqualsWithDelta(290.0, (float) $order->total_cogs, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $order->gross_profit, 0.01);
        $a2 = Warehouse::query()->where('code', 'A2')->value('id');
        $this->assertEqualsWithDelta(-13.0, (float) DB::table('stock_balances')->where('product_id', $this->productId)->where('warehouse_id', $a2)->value('qty'), 0.001);

        $this->get(route('orders.show', $order))->assertOk()->assertSee('Sistemdə yaradılıb')->assertSee('Cənub bölgəsi — Anbar 2')->assertSee('Çatdırılma cümə günü')->assertSee(route('orders.edit', $order), false);
    }

    public function test_manual_order_can_be_edited_and_deleted_but_imported_cannot(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), $this->payload());
        $order = SalesOrder::query()->where('order_no', '3931')->firstOrFail();

        $this->get(route('orders.edit', $order))->assertOk()->assertSee('VE11258');
        $this->put(route('orders.update', $order), $this->payload(['lines' => [['product_id' => $this->productId, 'op_type' => 'sale', 'qty' => 2, 'price' => 60]]]))
            ->assertRedirect(route('orders.show', $order));
        $this->assertSame(1, DB::table('sales_lines')->where('sales_order_id', $order->id)->count());
        $this->assertEqualsWithDelta(120.0, (float) $order->refresh()->total_amount, 0.001);

        $imported = SalesOrder::query()->where('order_no', '3930')->firstOrFail();
        $this->get(route('orders.edit', $imported))->assertForbidden();
        $this->delete(route('orders.destroy', $imported))->assertForbidden();

        $this->delete(route('orders.destroy', $order))->assertRedirect(route('orders.index'));
        $this->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
        $this->assertSame(0, DB::table('sales_lines')->where('sales_order_id', $order->id)->count());
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
