<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoicePaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fakturanın ödəniş vəziyyəti (FIFO) və müştəri ilə üzləşmə aktı.
 */
class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_close_oldest_invoice_first_and_akt_prints_balances(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj Elvin', 'voen' => '1234567890', 'created_at' => now(), 'updated_at' => now()]);
        $oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Yağ', 'created_at' => now(), 'updated_at' => now()]);
        $wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonth()->toDateString(), 'period' => now()->subMonth()->format('Y-m'), 'product_id' => $oil,
            'product_code' => 'OIL', 'qty' => 50, 'unit_cost' => 30, 'total_cost' => 1500, 'row_no' => 1, 'warehouse_id' => $wh, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin);
        foreach ([2, 1] as $qty) {
            $this->post(route('orders.store'), ['date' => now()->toDateString(), 'customer_id' => $customer, 'warehouse_id' => $wh, 'then' => 'invoice',
                'lines' => [['product_id' => $oil, 'op_type' => 'sale', 'qty' => $qty, 'price' => 118, 'discount' => 0]]])->assertSessionHasNoErrors();
        }
        [$first, $second] = DB::table('sales_invoices')->orderBy('id')->pluck('id')->all();

        $workplace = (int) DB::table('workplaces')->value('id');
        $register = (int) DB::table('cash_registers')->where('code', 'KS-001')->value('id');
        $this->post(route('finance.documents.store'), ['type' => 'cash_receipt', 'date' => now()->toDateString(), 'workplace_id' => $workplace, 'cash_register_id' => $register,
            'customer_id' => $customer, 'amount' => 100])->assertSessionHas('popup');
        $status = app(InvoicePaymentStatus::class)->forCustomers([$customer]);
        $this->assertSame(['partial', 136.0], [$status[$first]['status'], $status[$first]['open']]);
        $this->assertSame(['unpaid', 118.0], [$status[$second]['status'], $status[$second]['open']]);
        $this->get(route('invoices.index'))->assertOk()->assertSee('Qismən ödənilib')->assertSee('Ödənilməyib');
        $this->get(route('invoices.show', $first))->assertSee('Qismən ödənilib')->assertSee('136,00', false);

        $this->post(route('finance.documents.store'), ['type' => 'cash_receipt', 'date' => now()->toDateString(), 'workplace_id' => $workplace, 'cash_register_id' => $register,
            'customer_id' => $customer, 'amount' => 136]);
        $this->assertSame('paid', app(InvoicePaymentStatus::class)->forCustomers([$customer])[$first]['status']);

        $this->get(route('customers.akt', [$customer, 'from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()]))->assertOk()
            ->assertSee('Qarşılıqlı hesablaşmaların üzləşmə aktı')->assertSee('Qaraj Elvin')->assertSee('1234567890')->assertSee('354,00', false)->assertSee('118,00', false);
        $this->get(route('customers.show', $customer))->assertSee(route('customers.akt', $customer), false);
    }
}
