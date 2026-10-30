<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Müştəri kartı və kredit nəzarəti: əlaqə / ödəniş müddəti / limit / bloklama, gecikmiş borc, sifarişdə limit yoxlaması.
 */
class CustomerCreditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $managerId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->managerId = DB::table('managers')->insertGetId(['name' => 'Əkbər', 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = DB::table('products')->insertGetId(['code' => 'VE1', 'name' => 'MaxLife 4L', 'sale_price' => 50, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonths(3)->toDateString(), 'period' => now()->subMonths(3)->format('Y-m'), 'product_id' => $this->productId,
            'product_code' => 'VE1', 'qty' => 100, 'unit_cost' => 20, 'total_cost' => 2000, 'row_no' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function sale(int $customerId, string $date, float $amount): void
    {
        DB::table('sales_lines')->insert(['row_no' => (int) DB::table('sales_lines')->max('row_no') + 1, 'date' => $date, 'period' => substr($date, 0, 7), 'order_no' => 'IMP-'.$date,
            'customer_id' => $customerId, 'product_id' => $this->productId, 'product_code' => 'VE1', 'qty' => 1, 'price' => $amount, 'amount' => $amount,
            'net_amount' => $amount, 'op_type' => 'sale', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function order(int $customerId, float $price, array $extra = [])
    {
        return $this->post(route('orders.store'), array_merge([
            'date' => now()->toDateString(), 'customer_id' => $customerId, 'manager_id' => $this->managerId,
            'warehouse_id' => Warehouse::query()->where('code', 'A2')->value('id'),
            'lines' => [['product_id' => $this->productId, 'op_type' => 'sale', 'qty' => 1, 'price' => $price, 'discount' => 0]],
        ], $extra));
    }

    public function test_admin_creates_and_edits_a_customer_card(): void
    {
        $this->actingAs($this->admin)->get(route('customers.index'))->assertOk()->assertSee(route('customers.create'), false);
        $this->get(route('customers.create'))->assertOk()->assertSee('Ödəniş və kredit');
        $this->post(route('customers.store'), ['code' => 'C-100', 'name' => 'Qaraj Elvin', 'voen' => '12345', 'status' => 'active'])->assertSessionHasErrors('voen');
        $this->post(route('customers.store'), ['code' => 'C-100', 'name' => 'Qaraj Elvin', 'voen' => '1234567890', 'manager_id' => $this->managerId,
            'phone' => '+994 50 123 45 67', 'region' => 'Bakı — Nəsimi', 'payment_days' => 15, 'credit_limit' => 500, 'status' => 'active'])
            ->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'C-100');
        $customer = Customer::query()->where('code', 'C-100')->firstOrFail();
        $this->assertSame([15, '500.00', 'active'], [$customer->payment_days, $customer->credit_limit, $customer->status]);
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('+994 50 123 45 67')->assertSee('Bakı — Nəsimi')->assertSee('15 gün')->assertSee('Kartı düzəlt');

        // bloklamaq səbəb tələb edir
        $fields = ['code' => 'C-100', 'name' => 'Qaraj Elvin', 'status' => 'blocked'];
        $this->put(route('customers.update', $customer), $fields)->assertSessionHasErrors('block_reason');
        $this->put(route('customers.update', $customer), $fields + ['block_reason' => 'Ödəniş gecikir'])->assertSessionHasNoErrors();
        $this->get(route('customers.show', $customer))->assertSee('Müştəri bloklanıb');
        $this->get(route('customers.index', ['only' => 'blocked']))->assertSee('Qaraj Elvin');
    }

    public function test_manager_creates_own_customers_but_cannot_set_credit_terms(): void
    {
        $manager = User::factory()->create(['role' => 'menecer', 'manager_id' => $this->managerId]);
        $other = DB::table('managers')->insertGetId(['name' => 'Rasim', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($manager)->post(route('customers.store'), ['code' => 'C-200', 'name' => 'Yeni Mağaza', 'manager_id' => $other, 'credit_limit' => 99999, 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $customer = Customer::query()->where('code', 'C-200')->firstOrFail();
        $this->assertSame([$this->managerId, null], [$customer->manager_id, $customer->credit_limit]);

        $foreign = Customer::query()->create(['code' => 'C-201', 'name' => 'Başqasının', 'manager_id' => $other]);
        $this->get(route('customers.edit', $foreign))->assertForbidden();
    }

    public function test_overdue_debt_uses_payment_terms_and_order_respects_credit_limit(): void
    {
        $customer = Customer::query()->create(['code' => 'C-1', 'name' => 'Qaraj', 'manager_id' => $this->managerId, 'payment_days' => 10, 'credit_limit' => 150]);
        $this->sale($customer->id, now()->subDays(40)->toDateString(), 100);   // 30 gün gecikib
        $this->sale($customer->id, now()->subDays(5)->toDateString(), 20);     // hələ vaxtı çatmayıb
        DB::table('payments')->insert(['row_no' => 1, 'date' => now()->subDays(3)->toDateString(), 'period' => now()->format('Y-m'), 'customer_id' => $customer->id,
            'cash' => 30, 'total' => 30, 'created_at' => now(), 'updated_at' => now()]);
        app(AnalyticsRebuilder::class)->run();
        $balance = DB::table('customer_balances')->where('customer_id', $customer->id)->first();
        $this->assertSame([90.0, 70.0, 30], [(float) $balance->balance, (float) $balance->overdue_amount, (int) $balance->overdue_days]);

        // borc 90, limit 150: 50-lik sifariş keçir, sonra ikinci 50 limiti aşır (90 + 50 açıq + 50 > 150)
        $this->actingAs($this->admin)->get(route('orders.create', ['customer' => $customer->id]))->assertOk()
            ->assertViewHas('customers', fn ($c) => $c->firstWhere('id', $customer->id)['overdue'] === 70.0 && $c->firstWhere('id', $customer->id)['limit'] === 150.0);
        $this->order($customer->id, 50)->assertSessionHasNoErrors();
        $this->order($customer->id, 50)->assertSessionHasErrors('customer_id');
        $this->assertStringContainsString('Kredit limiti aşılır', session('errors')->first('customer_id'));
        // admin limiti aşmağa icazə verə bilər
        $this->order($customer->id, 50, ['credit_override' => 1])->assertSessionHasNoErrors();
        // menecer icazə verə bilməz
        $manager = User::factory()->create(['role' => 'menecer', 'manager_id' => $this->managerId]);
        $this->actingAs($manager)->order($customer->id, 50, ['credit_override' => 1])->assertSessionHasErrors('customer_id');

        // bloklu müştəriyə heç kim sifariş yaza bilməz
        $customer->forceFill(['status' => 'blocked', 'block_reason' => 'Məhkəmə'])->save();
        $this->actingAs($this->admin)->order($customer->id, 1, ['credit_override' => 1])->assertSessionHasErrors('customer_id');
        $this->assertStringContainsString('Məhkəmə', session('errors')->first('customer_id'));
    }

    public function test_invoice_gets_due_date_from_payment_terms(): void
    {
        $customer = Customer::query()->create(['code' => 'C-2', 'name' => 'Mağaza', 'manager_id' => $this->managerId, 'payment_days' => 14]);
        $this->actingAs($this->admin)->order($customer->id, 50, ['then' => 'invoice'])->assertSessionHasNoErrors();
        $invoice = DB::table('sales_invoices')->first();
        $this->assertSame(now()->addDays(14)->toDateString(), substr((string) $invoice->due_date, 0, 10));
        $this->get(route('invoices.show', $invoice->id))->assertSee('Ödəniş tarixi')->assertSee(now()->addDays(14)->format('d.m.Y'));
    }
}
