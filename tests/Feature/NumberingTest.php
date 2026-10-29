<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\NumberingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NumberingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $customer;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $this->product = DB::table('products')->insertGetId(['code' => 'P1', 'name' => 'Yağ', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function rule(string $key, string $pattern, string $mode = 'auto', string $reset = 'prefix', ?int $next = null)
    {
        return $this->put(route('numbering.update', $key), array_filter(['pattern' => $pattern, 'mode' => $mode, 'reset' => $reset, 'next' => $next]));
    }

    private function order(string $date, ?string $no = null, ?string $offered = null)
    {
        return $this->post(route('orders.store'), array_filter(['date' => $date, 'order_no' => $no, 'order_no_offered' => $offered, 'customer_id' => $this->customer,
            'warehouse_id' => Warehouse::query()->where('is_main', true)->value('id'),
            'lines' => [['product_id' => $this->product, 'op_type' => 'sale', 'qty' => 1, 'price' => 10]]]));
    }

    public function test_smart_order_number_with_date_and_workplace_segments(): void
    {
        $this->actingAs($this->admin)->get(route('numbering.index'))->assertOk()->assertSee('Nömrələmə servisi')->assertSee('TT-{SEQ:6}');
        $this->rule('sales_order', 'S{IYN}.{YY}{MM}{DD}.{SEQ:4}')->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('orders.create'))->assertSee('avtomatik')->assertDontSee('name="order_no"', false);

        $this->order('2026-02-15')->assertRedirect();
        $this->order('2026-02-15')->assertRedirect();
        $this->order('2026-02-16')->assertRedirect();
        $this->assertSame(['S01.260215.0001', 'S01.260215.0002', 'S01.260216.0001'], DB::table('sales_orders')->orderBy('id')->pluck('order_no')->all(),
            'yeni gün — sıra yenidən 1-dən');

        // davamlı sıra + növbəti nömrə 500-dən
        $this->rule('sales_order', 'S{IYN}.{YY}{MM}{DD}.{SEQ:4}', 'auto', 'never', 500)->assertSessionHasNoErrors();
        $this->order('2026-03-01');
        $this->assertSame('S01.260301.0500', DB::table('sales_orders')->latest('id')->value('order_no'));

        $this->getJson(route('numbering.preview', ['sales_order', 'pattern' => 'X{YYYY}-{SEQ:3}', 'mode' => 'auto']))->assertOk()
            ->assertJsonPath('number', 'X'.now()->format('Y').'-001')->assertJsonPath('errors', []);
    }

    public function test_modes_and_pattern_validation(): void
    {
        $this->actingAs($this->admin);
        $this->rule('sales_order', 'S-{SEQ:3}', 'auto_editable', 'never');
        $this->order('2026-02-15', 'S-001', 'S-001');
        $this->order('2026-02-15', 'XUSUSI-7', 'S-002');
        $this->assertSame(['S-001', 'XUSUSI-7'], DB::table('sales_orders')->orderBy('id')->pluck('order_no')->all(), 'dəyişdirilmiş nömrə saxlanılır');

        $this->rule('sales_order', 'S-{SEQ:3}', 'manual', 'never');
        $this->order('2026-02-15')->assertSessionHasErrors('order_no');

        $this->rule('sales_order', 'S-{XYZ}', 'auto')->assertSessionHasErrors('pattern_sales_order');
        $this->rule('sales_order', 'S-{YYYY}', 'auto')->assertSessionHasErrors('pattern_sales_order');
        $this->rule('delivery_note', 'TT-{YYYY}{MM}{DD}-{IY}-{ANB}-{SN}-{SEQ:9}-ABCDEFGHIJ')->assertSessionHasErrors('pattern_delivery_note');

        // mal kodu: avtomatik rejimdə boş kod sistemdən gəlir
        $this->rule('product', 'VL-{SEQ:4}', 'auto', 'never');
        $this->post(route('products.store'), ['name' => 'Yeni yağ', 'base_unit_id' => DB::table('units')->value('id'), 'vat_mode' => 'standard', 'vat_rate' => 18, 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('products')->where('code', 'VL-0001')->exists());
    }

    public function test_imported_series_continues_and_barcode_like_numbers_are_ignored(): void
    {
        foreach (['№3929', '№3930', '№8710941033518', 'NOMRESIZ-20250328'] as $no) {
            DB::table('sales_orders')->insert(['order_no' => $no, 'date' => '2026-03-01', 'period' => '2026-03', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($this->admin);
        $this->rule('sales_order', '№{SEQ}', 'auto_editable', 'never')->assertSessionHasNoErrors();
        $this->assertSame('№3931', app(NumberingService::class)->preview('sales_order'));
    }

    public function test_documents_use_their_rules(): void
    {
        $this->actingAs($this->admin);
        $this->rule('fin_cash_opening', 'KA{IYN}/{YY}-{SEQ:3}')->assertSessionHasErrors('pattern_fin_cash_opening');
        $this->rule('fin_cash_opening', 'KA{IYN}.{YY}-{SEQ:3}')->assertSessionHasNoErrors();
        $this->rule('stock_doc_count_surplus', 'SAR.{ANB}.{SEQ:2}')->assertSessionHasNoErrors();
        $register = DB::table('cash_registers')->where('code', 'KS-001')->value('id');
        $this->post(route('finance.documents.store'), ['type' => 'cash_opening', 'date' => '2026-05-05', 'workplace_id' => DB::table('workplaces')->value('id'), 'cash_register_id' => $register, 'amount' => 10]);
        $this->assertSame('KA01.26-001', DB::table('finance_documents')->value('doc_no'));
        $this->post(route('stock-docs.store'), ['type' => 'count_surplus', 'date' => now()->toDateString(), 'warehouse_id' => Warehouse::query()->where('code', 'A2')->value('id'),
            'lines' => [['product_id' => $this->product, 'counted_qty' => 3]]]);
        $this->assertSame('SAR.A2.01', DB::table('stock_documents')->value('doc_no'));
        $this->assertSame('TT-000001', app(NumberingService::class)->preview('delivery_note'), 'dəyişdirilməyən qayda əvvəlki kimi');
        Carbon::setTestNow();
    }
}
