<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\VatLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ƏDV və depozit hesabı: aylıq hesablanmış / əvəzləşdirilən ƏDV və tərəflər üzrə ƏDV borcu (depozit çıxarışı VÖEN ilə).
 */
class VatLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_vat_and_counterparty_vat_debt(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj Elvin', 'voen' => '1234567890', 'created_at' => now(), 'updated_at' => now()]);
        $oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Yağ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonth()->toDateString(), 'period' => now()->subMonth()->format('Y-m'), 'product_id' => $oil,
            'product_code' => 'OIL', 'qty' => 50, 'unit_cost' => 30, 'total_cost' => 1500, 'row_no' => 1, 'warehouse_id' => $wh, 'created_at' => now(), 'updated_at' => now()]);
        // 3 × 118 → ƏDV 54; 1 ədəd qaytarılır → ƏDV 18
        $this->post(route('orders.store'), ['date' => now()->toDateString(), 'customer_id' => $customer, 'warehouse_id' => $wh, 'then' => 'invoice',
            'lines' => [['product_id' => $oil, 'op_type' => 'sale', 'qty' => 3, 'price' => 118, 'discount' => 0]]])->assertSessionHasNoErrors();
        $invoice = DB::table('sales_invoices')->first();
        $line = DB::table('sales_lines')->where('sales_invoice_id', $invoice->id)->first();
        $this->post(route('sales-returns.store'), ['date' => now()->toDateString(), 'sales_invoice_id' => $invoice->id, 'customer_id' => $customer, 'warehouse_id' => $wh,
            'reason' => 'damaged', 'lines' => [['product_id' => $oil, 'sales_line_id' => $line->id, 'qty' => 1, 'price' => 1]]])->assertSessionHas('popup');

        // idxal olunmuş (fakturasız) satış: ƏDV malın rejimi ilə — 236 ₼ → 36 ₼
        DB::table('sales_lines')->insert(['row_no' => 1, 'date' => now()->toDateString(), 'period' => now()->format('Y-m'), 'order_no' => 'IMP-1', 'customer_id' => $customer,
            'product_id' => $oil, 'product_code' => 'OIL', 'qty' => 2, 'net_amount' => 236, 'op_type' => 'sale', 'created_at' => now(), 'updated_at' => now()]);

        $deposit = DB::table('bank_accounts')->insertGetId(['name' => 'EDV DEPOZİT', 'kind' => 'deposit', 'created_at' => now(), 'updated_at' => now()]);
        $tx = fn (array $v) => DB::table('bank_transactions')->insert($v + ['bank_account_id' => $deposit, 'date' => now()->toDateString(), 'period' => now()->format('Y-m'),
            'amount_in' => 0, 'amount_out' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $tx(['counterparty' => 'Qaraj Elvin VÖEN 1234567890', 'amount_in' => 20]);
        $tx(['counterparty' => 'Neft MMC', 'description' => 'ƏDV ödənişi, VÖEN: 9988776655', 'amount_out' => 36]);
        DB::table('bank_flow_balances')->insert(['year' => now()->year, 'period' => now()->format('Y-m'), 'account' => 'EDV DEPOZİT', 'amount' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $einvoice = ['kind' => 'Təqdim etmə', 'sender_tin' => '9988776655', 'sender_name' => 'Neft MMC', 'issued_at' => now()->toDateString(), 'items' => '[]', 'created_at' => now(), 'updated_at' => now()];
        DB::table('incoming_einvoices')->insert([
            $einvoice + ['serial_number' => 'MT1', 'status' => 'Təsdiqləndi', 'vat_amount' => 90],
            $einvoice + ['serial_number' => 'MT2', 'status' => 'Ləğv edildi', 'vat_amount' => 1000],
        ]);

        $ledger = app(VatLedgerService::class);
        $month = $ledger->monthly(now()->year)[now()->format('Y-m')];
        $this->assertEquals(['output' => 90, 'returns' => 18, 'input_einvoice' => 90, 'payable' => -18, 'deposit_in' => 20, 'deposit_out' => 36, 'deposit_balance' => 500],
            array_intersect_key($month, array_flip(['output', 'returns', 'input_einvoice', 'payable', 'deposit_in', 'deposit_out', 'deposit_balance'])));
        $c = $ledger->customers()->firstWhere('id', $customer);
        $this->assertEquals([72, 20, 52], [$c->vat, $c->received, $c->open]);
        $s = $ledger->suppliers()->firstWhere('tin', '9988776655');
        $this->assertEquals([90, 36, 54], [$s->vat, $s->paid, $s->open]);

        $this->get(route('vat.index'))->assertOk()->assertSee('Büdcəyə (təxmini)')->assertSee('500,00', false);
        $this->get(route('vat.index', ['tab' => 'customers']))->assertOk()->assertSee('Qaraj Elvin')->assertSee('52,00', false);
        $this->get(route('vat.index', ['tab' => 'suppliers']))->assertOk()->assertSee('Neft MMC')->assertSee('54,00', false);
        $this->actingAs(User::factory()->create(['role' => 'anbardar']))->get(route('vat.index'))->assertForbidden();
    }
}
