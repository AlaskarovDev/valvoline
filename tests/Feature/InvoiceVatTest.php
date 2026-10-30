<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Satış fakturasında ƏDV: qiymətlər ƏDV daxildir, sətir üzrə rejim / dərəcə fakturalama anında saxlanılır.
 */
class InvoiceVatTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_splits_vat_per_product_mode_and_prints_summary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Yağ', 'vat_mode' => 'standard', 'vat_rate' => 18, 'created_at' => now(), 'updated_at' => now()]);
        $exempt = DB::table('products')->insertGetId(['code' => 'EXM', 'name' => 'Azad mal', 'vat_mode' => 'exempt', 'vat_rate' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$oil, $exempt] as $i => $p) {
            DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonth()->toDateString(), 'period' => now()->subMonth()->format('Y-m'), 'product_id' => $p,
                'product_code' => 'X'.$i, 'qty' => 10, 'unit_cost' => 10, 'total_cost' => 100, 'row_no' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($admin)->post(route('orders.store'), [
            'date' => now()->toDateString(), 'customer_id' => $customer, 'warehouse_id' => Warehouse::query()->where('code', 'A2')->value('id'), 'then' => 'invoice',
            'lines' => [
                ['product_id' => $oil, 'op_type' => 'sale', 'qty' => 2, 'price' => 59, 'discount' => 0],      // 118 → ƏDV 18
                ['product_id' => $exempt, 'op_type' => 'sale', 'qty' => 1, 'price' => 40, 'discount' => 0],   // 40 → ƏDV 0
            ],
        ])->assertSessionHasNoErrors();

        $invoice = DB::table('sales_invoices')->first();
        $this->assertEqualsWithDelta(158.0, (float) $invoice->total_amount, 0.001);
        $this->assertEqualsWithDelta(18.0, (float) $invoice->vat_amount, 0.001);
        $this->assertEqualsWithDelta(140.0, (float) $invoice->amount_ex_vat, 0.001);
        $lines = DB::table('sales_lines')->where('sales_invoice_id', $invoice->id)->orderBy('row_no')->get();
        $this->assertSame([['standard', 18.0, 18.0], ['exempt', 0.0, 0.0]], $lines->map(fn ($l) => [$l->vat_mode, (float) $l->vat_rate, (float) $l->vat_amount])->all());

        // mal parametri sonradan dəyişsə də faktura dəyişmir
        DB::table('products')->where('id', $oil)->update(['vat_mode' => 'exempt']);
        $this->get(route('invoices.show', [$invoice->id, 'print' => 1]))->assertOk()
            ->assertSee('ƏDV 18 %')->assertSee('vergi bazası')->assertSee('ƏDV-dən azad')->assertSee('18,00', false)->assertSee('140,00', false);
        $this->get(route('invoices.show', $invoice->id))->assertOk()->assertSee('ƏDV-siz məbləğ');
        $this->get(route('invoices.index'))->assertOk()->assertSee('ƏDV-siz');
    }
}
