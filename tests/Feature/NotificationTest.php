<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bildirişlər: gecikmiş borc, fakturalanmamış təhvil, vaxtı bitmiş partiya — rola və menecerə görə süzülür.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_follow_permissions_and_refresh_after_payment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $rasim = DB::table('managers')->insertGetId(['name' => 'Rasim', 'created_at' => now(), 'updated_at' => now()]);
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj Elvin', 'manager_id' => $rasim, 'payment_days' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Yağ', 'created_at' => now(), 'updated_at' => now()]);
        $wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonths(3)->toDateString(), 'period' => now()->subMonths(3)->format('Y-m'), 'product_id' => $oil,
            'product_code' => 'OIL', 'qty' => 50, 'unit_cost' => 30, 'total_cost' => 1500, 'row_no' => 1, 'warehouse_id' => $wh, 'created_at' => now(), 'updated_at' => now()]);
        // FEFO: qalıq əvvəlcə gec bitən partiyaya yazılır, 47-dən 45-i yenidir, 2-si vaxtı keçmiş partiyada qalır
        DB::table('product_batches')->insert([
            ['product_id' => $oil, 'batch_no' => 'B-OLD', 'expiry_date' => now()->subDay()->toDateString(), 'qty' => 5, 'warehouse_id' => $wh, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $oil, 'batch_no' => 'B-NEW', 'expiry_date' => now()->addYears(2)->toDateString(), 'qty' => 45, 'warehouse_id' => $wh, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($admin);
        // 40 gün əvvəl fakturalanıb, ödəniş müddəti 10 gün → gecikmiş borc; bu gün göndərilib, faktura yoxdur
        $this->travel(-40)->days();
        $this->post(route('orders.store'), ['date' => now()->toDateString(), 'customer_id' => $customer, 'warehouse_id' => $wh, 'then' => 'invoice',
            'lines' => [['product_id' => $oil, 'op_type' => 'sale', 'qty' => 2, 'price' => 100, 'discount' => 0]]])->assertSessionHasNoErrors();
        $this->travelBack();
        $this->post(route('orders.store'), ['date' => now()->toDateString(), 'customer_id' => $customer, 'warehouse_id' => $wh, 'then' => 'send',
            'lines' => [['product_id' => $oil, 'op_type' => 'sale', 'qty' => 1, 'price' => 100, 'discount' => 0]]])->assertSessionHasNoErrors();
        $delivery = DB::table('delivery_notes')->orderByDesc('id')->value('doc_no');

        $keys = fn () => array_column($this->getJson(route('notifications.summary'))->assertOk()->json('alerts'), 'key');
        $this->assertContains('overdue_debt', $keys());
        $this->assertContains('uninvoiced', $keys());
        $this->assertContains('expired', $keys());
        $this->get(route('notifications.index'))->assertOk()->assertSee('Gecikmiş müştəri borcu')->assertSee('Vaxtı bitmiş partiyalar');
        $this->get(route('deliveries.index', ['preset' => 'all', 'only' => 'uninvoiced']))->assertOk()->assertSee($delivery);
        $this->get(route('dashboard'))->assertSee(route('notifications.index'), false);

        // anbardar yalnız anbar bildirişlərini görür
        $this->actingAs(User::factory()->create(['role' => 'anbardar']));
        $this->assertSame(['expired'], array_values(array_intersect($keys(), ['overdue_debt', 'uninvoiced', 'expired'])));

        // başqa menecerin nümayəndəsi Rasimin müştərisinin borcunu görmür
        $other = DB::table('managers')->insertGetId(['name' => 'Əkbər', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'menecer', 'manager_id' => $other]));
        $this->assertEmpty(array_intersect($keys(), ['overdue_debt', 'uninvoiced']));

        // borc ödənəndə bildiriş (keşə baxmayaraq) yox olur
        $this->actingAs($admin);
        $this->post(route('finance.documents.store'), ['type' => 'cash_receipt', 'date' => now()->toDateString(), 'workplace_id' => (int) DB::table('workplaces')->value('id'),
            'cash_register_id' => (int) DB::table('cash_registers')->where('code', 'KS-001')->value('id'), 'customer_id' => $customer, 'amount' => 300])->assertSessionHas('popup');
        $this->assertNotContains('overdue_debt', $keys());
    }
}
