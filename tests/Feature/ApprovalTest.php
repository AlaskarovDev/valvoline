<?php

namespace Tests\Feature;

use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Təsdiq axını: minimum qiymət / endirim / kredit limiti aşan sifariş təsdiqə gedir, təsdiqlənməyənə qədər göndərilmir.
 */
class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_breaking_limits_waits_for_manager_approval(): void
    {
        $managerId = DB::table('managers')->insertGetId(['name' => 'Rasim', 'created_at' => now(), 'updated_at' => now()]);
        $rep = User::factory()->create(['role' => 'menecer', 'manager_id' => $managerId]);
        $boss = User::factory()->create(['role' => 'rehber']);
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'manager_id' => $managerId, 'created_at' => now(), 'updated_at' => now()]);
        $oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Yağ', 'min_price' => 10, 'max_discount_pct' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonth()->toDateString(), 'period' => now()->subMonth()->format('Y-m'), 'product_id' => $oil,
            'product_code' => 'OIL', 'qty' => 50, 'unit_cost' => 5, 'total_cost' => 250, 'row_no' => 1, 'warehouse_id' => $wh, 'created_at' => now(), 'updated_at' => now()]);
        $order = fn (float $price, array $extra = []) => ['date' => now()->toDateString(), 'customer_id' => $customer, 'warehouse_id' => $wh,
            'lines' => [['product_id' => $oil, 'op_type' => 'sale', 'qty' => 2, 'price' => $price, 'discount' => 0]]] + $extra;

        // təsdiqə göndərilmədən minimum qiymətdən aşağı satış rədd olunur
        $this->actingAs($rep)->post(route('orders.store'), $order(8))->assertSessionHasErrors('lines.0.price');
        $this->assertSame(0, SalesOrder::query()->count());

        // təsdiqə göndərilir: sifariş yaranır, amma göndərilmir
        $this->post(route('orders.store'), $order(8, ['request_approval' => 1, 'then' => 'send']))->assertSessionHasNoErrors()->assertSessionHas('error');
        $so = SalesOrder::query()->sole();
        $this->assertSame(['pending', 'open'], [$so->approval_status, $so->status]);
        $this->assertSame(0, DB::table('delivery_notes')->count());
        $this->get(route('orders.show', $so))->assertSee('Təsdiq gözləyir');
        $this->get(route('approvals.index'))->assertForbidden();

        // rəhbər görür: bildiriş və səbəb
        $this->actingAs($boss);
        $this->assertContains('approvals_pending', array_column($this->getJson(route('notifications.summary'))->json('alerts'), 'key'));
        $this->get(route('approvals.index'))->assertOk()->assertSee('minimum satış qiymətindən');
        $request = DB::table('approval_requests')->sole();
        $this->post(route('approvals.decide', $request->id), ['decision' => 'reject', 'note' => 'Qiymət çox aşağıdır'])->assertSessionHas('status');
        $this->actingAs($rep)->post(route('orders.send', $so))->assertSessionHas('error');

        // nümayəndə qiyməti düzəldir (hələ də limit aşır) → yeni sorğu, köhnəsi əvəzlənir
        $this->put(route('orders.update', $so), $order(9, ['request_approval' => 1, 'order_no' => $so->order_no]))->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'pending'], DB::table('approval_requests')->orderBy('id')->pluck('status')->all());
        $this->actingAs($boss)->post(route('approvals.decide', DB::table('approval_requests')->max('id')), ['decision' => 'approve'])->assertSessionHas('status');
        $this->actingAs($rep)->post(route('orders.send', $so))->assertSessionHas('popup');
        $this->assertSame(1, DB::table('delivery_notes')->count());

        // rəhbər özü «icazə verirəm» ilə təsdiqsiz yazır; bloklu müştəri isə həmişə qadağadır
        $this->actingAs($boss)->post(route('orders.store'), $order(8, ['credit_override' => 1]))->assertSessionHasNoErrors();
        $this->assertNull(SalesOrder::query()->latest('id')->first()->approval_status);
        DB::table('customers')->where('id', $customer)->update(['status' => 'blocked', 'block_reason' => 'Borc']);
        $this->actingAs($rep)->post(route('orders.store'), $order(12, ['request_approval' => 1]))->assertSessionHasErrors('customer_id');
    }
}
