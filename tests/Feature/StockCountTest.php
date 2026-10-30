<?php

namespace Tests\Feature;

use App\Models\StockCount;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use App\Services\StockCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sayım: barkod (qutu barkodu əmsalla), əl ilə miqdar, müqayisə, tamamlananda artıq / əskik sənədləri; yığım vərəqi.
 */
class StockCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_barcode_count_creates_surplus_and_shortage_documents(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        $product = fn (string $code, ?string $barcode) => DB::table('products')->insertGetId(['code' => $code, 'name' => 'Mal '.$code, 'barcode' => $barcode, 'current_avg_cost' => 10, 'created_at' => now(), 'updated_at' => now()]);
        [$oil, $ant, $fil] = [$product('OIL', '4820001'), $product('ANT', null), $product('FIL', null)];
        $box = DB::table('product_units')->insertGetId(['product_id' => $oil, 'unit_id' => DB::table('units')->where('name', 'qutu')->value('id'), 'factor' => 12, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_barcodes')->insert(['product_id' => $oil, 'product_unit_id' => $box, 'barcode' => 'BOX-OIL', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$oil, 'OIL', 30], [$ant, 'ANT', 10], [$fil, 'FIL', 5]] as $i => [$id, $code, $qty]) {
            DB::table('purchase_lines')->insert(['batch_no' => $i + 1, 'date' => now()->subMonth()->toDateString(), 'period' => now()->subMonth()->format('Y-m'), 'product_id' => $id,
                'product_code' => $code, 'qty' => $qty, 'unit_cost' => 10, 'total_cost' => $qty * 10, 'row_no' => $i + 1, 'warehouse_id' => $wh, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(AnalyticsRebuilder::class)->run();

        $this->get(route('stock-counts.index'))->assertOk();
        $this->post(route('stock-counts.store'), ['warehouse_id' => $wh, 'date' => now()->toDateString(), 'scope' => 'partial'])->assertRedirect();
        $count = StockCount::query()->sole();
        $this->assertSame('SY-000001', $count->doc_no);
        $scan = fn (array $body) => $this->postJson(route('stock-counts.scan', $count), $body + ['qty' => 1, 'mode' => 'add'])->assertOk()->json();
        $this->assertEquals(1, $scan(['code' => '4820001'])['counted']);
        $this->assertEquals(13, $scan(['code' => 'BOX-OIL'])['counted'], 'qutu barkodu 12 ədəddir');
        $this->assertEquals(12, $scan(['product_id' => $ant, 'qty' => 12, 'mode' => 'set'])['counted']);
        $this->assertFalse($scan(['code' => 'YOX'])['ok']);
        $page = $this->get(route('stock-counts.show', $count))->assertOk()->assertSee('Mal OIL');
        $this->assertEquals(['OIL' => -17, 'ANT' => 2], $page->viewData('rows')->pluck('diff', 'code')->all());

        // tam sayımda sayılmayan qalıqlı mal 0 sayılır
        $full = StockCount::query()->create(['doc_no' => 'SY-T', 'date' => now()->toDateString(), 'warehouse_id' => $wh, 'scope' => 'full']);
        $this->assertEqualsCanonicalizing(['OIL' => -30, 'ANT' => -10, 'FIL' => -5], app(StockCountService::class)->comparison($full)->pluck('diff', 'code')->all());

        $this->post(route('stock-counts.complete', $count))->assertSessionHas('popup', fn ($p) => count($p['docs']) === 2);
        $count->refresh();
        $this->assertNotNull($count->completed_at);
        $shortage = DB::table('stock_document_lines')->where('stock_document_id', $count->shortage_document_id)->sole();
        $surplus = DB::table('stock_document_lines')->where('stock_document_id', $count->surplus_document_id)->sole();
        $this->assertEquals([$oil, 17, 30, 13], [$shortage->product_id, (float) $shortage->qty, (float) $shortage->system_qty, (float) $shortage->counted_qty]);
        $this->assertEquals([$ant, 2], [$surplus->product_id, (float) $surplus->qty]);
        $this->assertFalse($scan(['code' => '4820001'])['ok'], 'tamamlanmış sayım dəyişmir');
        app(AnalyticsRebuilder::class)->run();
        $this->assertEquals([13, 12, 5], DB::table('stock_balances')->where('warehouse_id', $wh)->whereIn('product_id', [$oil, $ant, $fil])->orderBy('product_id')->pluck('qty')->map(fn ($q) => (float) $q)->all());

        // yığım vərəqi: açıq sifarişlərin malları cəmlənir
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([3, 2] as $qty) {
            $this->post(route('orders.store'), ['date' => now()->toDateString(), 'customer_id' => $customer, 'warehouse_id' => $wh,
                'lines' => [['product_id' => $oil, 'op_type' => 'sale', 'qty' => $qty, 'price' => 20, 'discount' => 0]]])->assertSessionHasNoErrors();
        }
        $picking = $this->get(route('orders.picking'))->assertOk()->assertSee('Yığım vərəqi')->assertSee('Mal OIL');
        $this->assertEquals(5, $picking->viewData('rows')->sole()->qty);

        $this->actingAs(User::factory()->create(['role' => 'kassir']))->get(route('stock-counts.index'))->assertForbidden();
    }
}
