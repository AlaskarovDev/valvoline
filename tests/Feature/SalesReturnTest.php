<?php

namespace Tests\Feature;

use App\Models\SalesReturnDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Müştəri qaytarma qaiməsi: fakturadan (qiymət fakturadan, miqdar satılandan çox ola bilməz) və fakturasız.
 */
class SalesReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $customer;

    private int $oil;

    private int $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $this->oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Yağ 4L', 'vat_mode' => 'standard', 'vat_rate' => 18, 'created_at' => now(), 'updated_at' => now()]);
        $this->wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonth()->toDateString(), 'period' => now()->subMonth()->format('Y-m'), 'product_id' => $this->oil,
            'product_code' => 'OIL', 'qty' => 20, 'unit_cost' => 30, 'total_cost' => 600, 'row_no' => 1, 'warehouse_id' => $this->wh, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function state(): array
    {
        app(AnalyticsRebuilder::class)->run();

        return [(float) DB::table('customer_balances')->where('customer_id', $this->customer)->value('balance'),
            (float) DB::table('stock_balances')->where('product_id', $this->oil)->where('warehouse_id', $this->wh)->value('qty')];
    }

    public function test_return_from_invoice_reduces_debt_and_restocks(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), ['date' => now()->toDateString(), 'customer_id' => $this->customer, 'warehouse_id' => $this->wh, 'then' => 'invoice',
            'lines' => [['product_id' => $this->oil, 'op_type' => 'sale', 'qty' => 4, 'price' => 59, 'discount' => 0]]])->assertSessionHasNoErrors();
        $invoice = DB::table('sales_invoices')->first();
        $this->assertSame([236.0, 16.0], $this->state());

        $this->get(route('invoices.show', $invoice->id))->assertSee(route('sales-returns.create', ['invoice' => $invoice->id]), false);
        $this->get(route('sales-returns.create', ['invoice' => $invoice->id]))->assertOk()->assertSee('Fakturadan qaytarılır');
        $line = DB::table('sales_lines')->where('sales_invoice_id', $invoice->id)->first();
        $payload = fn ($qty, $price = 1) => ['date' => now()->toDateString(), 'sales_invoice_id' => $invoice->id, 'customer_id' => $this->customer, 'warehouse_id' => $this->wh,
            'reason' => 'damaged', 'lines' => [['product_id' => $this->oil, 'sales_line_id' => $line->id, 'qty' => $qty, 'price' => $price]]];

        // satılandan çox qaytarmaq olmaz; qiymət fakturadan gəlir (formadakı 1 ₼ nəzərə alınmır)
        $this->post(route('sales-returns.store'), $payload(5))->assertSessionHasErrors('lines');
        $this->post(route('sales-returns.store'), $payload(1))->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'QQ-000001');
        $doc = SalesReturnDocument::query()->firstOrFail();
        $this->assertSame([59.0, 9.0], [(float) $doc->total_amount, (float) $doc->vat_amount]);
        $this->assertSame([177.0, 17.0], $this->state());
        // qalan 3-dən 4 qaytarmaq olmaz
        $this->post(route('sales-returns.store'), $payload(4))->assertSessionHasErrors('lines');

        $this->get(route('sales-returns.show', $doc))->assertOk()->assertSee('Zədələnib')->assertSee('59,00', false);
        $this->get(route('sales-returns.show', [$doc, 'print' => 1]))->assertOk()->assertSee('Qaytarma qaiməsi № QQ-000001');
        $this->get(route('sales-returns.index'))->assertOk()->assertSee('QQ-000001');
        $this->get(route('returns.index', ['preset' => 'all']))->assertOk()->assertSee('Qaraj');

        $this->delete(route('sales-returns.destroy', $doc), ['reason' => 'səhv'])->assertSessionHas('popup');
        $this->assertSame([236.0, 16.0], $this->state());
    }

    public function test_return_without_invoice_uses_entered_price(): void
    {
        $this->actingAs($this->admin)->get(route('sales-returns.create'))->assertOk()->assertSee('Fakturasız qaytarma');
        $this->post(route('sales-returns.store'), ['date' => now()->toDateString(), 'customer_id' => $this->customer, 'warehouse_id' => $this->wh, 'reason' => 'quality',
            'lines' => [['product_id' => $this->oil, 'qty' => 2, 'price' => 50]]])->assertSessionHas('popup');
        $this->assertSame([-100.0, 22.0], $this->state());

        $rep = User::factory()->create(['role' => 'menecer', 'manager_id' => DB::table('managers')->insertGetId(['name' => 'Əkbər', 'created_at' => now(), 'updated_at' => now()])]);
        $this->actingAs($rep)->get(route('sales-returns.show', SalesReturnDocument::query()->first()))->assertForbidden();
    }
}
