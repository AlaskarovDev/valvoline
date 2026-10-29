<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $piece;

    private int $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->piece = (int) DB::table('units')->where('name', 'ədəd')->value('id');
        $this->box = (int) DB::table('units')->where('name', 'qutu')->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'VE11258', 'name' => 'Valvoline MaxLife 5W-40 4L', 'barcode' => '8710941000001', 'extra_barcodes' => '8710941000002',
            'brand_id' => DB::table('brands')->where('name', 'Valvoline')->value('id'), 'base_unit_id' => $this->piece,
            'vat_mode' => 'standard', 'vat_rate' => 18, 'hs_code' => '2710198100', 'viscosity' => '5W-40', 'specifications' => 'API SN, ACEA A3/B4',
            'min_stock_qty' => 10, 'max_stock_qty' => 100, 'min_price' => 40, 'max_discount_pct' => 15, 'is_active' => 1,
            'units' => [['unit_id' => $this->box, 'factor' => 4, 'barcode' => '18710941000008']], 'sale_unit' => $this->box,
            'sale_price' => 50, 'purchase_price' => 30, 'prices_vat_included' => 1,
        ], $overrides);
    }

    public function test_migration_seeds_reference_data(): void
    {
        $this->assertSame(['Alış qiyməti', 'Satış qiyməti (baza)', 'Topdan'], DB::table('price_lists')->orderBy('name')->pluck('name')->all());
        $this->assertTrue(DB::table('units')->where('name', 'qutu')->exists());
        $this->actingAs($this->admin)->get(route('product-settings.index', ['tab' => 'price-lists']))->assertOk()->assertSee('Topdan');
        $this->post(route('product-settings.store', 'brands'), ['name' => 'Castrol'])->assertRedirect();
        $this->post(route('product-settings.store', 'price-lists'), ['name' => 'Diler', 'type' => 'sale', 'vat_included' => 0, 'is_default' => 1])->assertRedirect();
        $this->assertSame(['Diler'], DB::table('price_lists')->where('type', 'sale')->where('is_default', true)->pluck('name')->all(), 'yalnız bir əsas');
        $valvoline = DB::table('brands')->where('name', 'Valvoline')->value('id');
        $this->post(route('products.store'), $this->payload())->assertRedirect();
        $this->delete(route('product-settings.destroy', ['brands', $valvoline]))->assertSessionHas('error');
    }

    public function test_product_is_created_with_units_barcodes_and_price_cards(): void
    {
        $this->actingAs($this->admin)->get(route('products.create'))->assertOk()->assertSee('Vahidlər və qablaşdırma')->assertSee('ƏDV kateqoriyası');
        $this->post(route('products.store'), $this->payload())->assertRedirect()->assertSessionHas('popup');
        $product = Product::query()->where('code', 'VE11258')->firstOrFail();

        $this->assertSame(['manual', '8710941000001', 'API SN, ACEA A3/B4'], [$product->source, $product->barcode, $product->specifications]);
        $unit = DB::table('product_units')->where('product_id', $product->id)->first();
        $this->assertEquals([4, 1], [(float) $unit->factor, (int) $unit->is_sale_default]);
        $this->assertSame(['18710941000008', '8710941000002'], DB::table('product_barcodes')->where('product_id', $product->id)->orderBy('barcode')->pluck('barcode')->all());
        $this->assertSame(['purchase', 'sale'], DB::table('price_cards')->where('product_id', $product->id)->orderBy('type')->pluck('type')->all());

        $this->get(route('products.show', $product))->assertOk()->assertSee('qutu')->assertSee('18710941000008')->assertSee('Satış qiyməti (baza)')->assertSee('2710198100');
        $this->get(route('products.index', ['state' => 'all']))->assertOk()->assertSee('VE11258')->assertSee('Bitib');
        $this->get(route('products.labels', ['ids' => [$product->id]]))->assertOk()->assertSee('<svg', false)->assertSee('8710941000001');
        $this->get(route('products.index', ['state' => 'all', 'export' => 'xlsx']))->assertOk();

        // barkod unikallığı və əsas vahid təkrarı
        $this->post(route('products.store'), $this->payload(['code' => 'X2', 'extra_barcodes' => '', 'units' => [['unit_id' => $this->piece, 'factor' => 1]]]))
            ->assertSessionHasErrors(['barcode', 'units']);

        $this->put(route('products.update', $product), $this->payload(['name' => 'MaxLife 4L', 'is_active' => 0, 'units' => []]))->assertRedirect();
        $this->assertSame(['MaxLife 4L', false, 0], [$product->refresh()->name, $product->is_active, DB::table('product_units')->where('product_id', $product->id)->count()]);
    }

    public function test_price_resolution_priority_dates_vat_and_quantity(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->payload());
        $id = (int) Product::query()->where('code', 'VE11258')->value('id');
        $wholesale = (int) DB::table('price_lists')->where('name', 'Topdan')->value('id');
        $customer = Customer::query()->create(['code' => 'C1', 'name' => 'Qaraj', 'price_list_id' => $wholesale]);
        $other = Customer::query()->create(['code' => 'C2', 'name' => 'Başqa']);
        $prices = app(PriceService::class);
        $today = now()->startOfDay();

        $this->assertSame(50.0, $prices->salePrice($id, $other, $today)['price']);
        // topdan qiymət ƏDV xaric: 40 × 1,18 = 47,2
        $this->post(route('prices.store'), ['product_id' => $id, 'type' => 'sale', 'price_list_id' => $wholesale, 'price' => 40, 'vat_included' => 0, 'valid_from' => $today->toDateString()])->assertRedirect();
        $this->assertSame(47.2, $prices->salePrice($id, $customer, $today)['price']);
        $this->assertSame(50.0, $prices->salePrice($id, $other, $today)['price'], 'başqa müştəriyə baza qiymət');
        // müştəriyə özəl — yalnız gələn aydan
        $this->post(route('prices.store'), ['product_id' => $id, 'type' => 'sale', 'customer_id' => $customer->id, 'price' => 45, 'vat_included' => 1, 'valid_from' => $today->copy()->addMonth()->toDateString()]);
        $this->assertSame(47.2, $prices->salePrice($id, $customer, $today)['price']);
        $this->assertSame('customer', $prices->salePrice($id, $customer, $today->copy()->addMonth())['source']);
        // həcm qiyməti
        $this->post(route('prices.store'), ['product_id' => $id, 'type' => 'sale', 'price_list_id' => $wholesale, 'price' => 35, 'vat_included' => 0, 'min_qty' => 100, 'valid_from' => $today->toDateString()]);
        $this->assertSame(41.3, $prices->salePrice($id, $customer, $today, 120)['price']);

        // yeni baza kart köhnəsini bağlayır
        $base = (int) DB::table('price_lists')->where('name', 'Satış qiyməti (baza)')->value('id');
        $this->post(route('prices.store'), ['product_id' => $id, 'type' => 'sale', 'price_list_id' => $base, 'price' => 55, 'vat_included' => 1, 'valid_from' => $today->copy()->addDays(10)->toDateString()]);
        $this->assertSame(50.0, $prices->salePrice($id, $other, $today)['price']);
        $this->assertSame(55.0, $prices->salePrice($id, $other, $today->copy()->addDays(10))['price']);
        $this->assertSame($today->copy()->addDays(9)->toDateString(), Carbon::parse(DB::table('price_cards')->where('product_id', $id)->where('price', 50)->value('valid_to'))->toDateString());

        $this->getJson(route('prices.customer', ['customer_id' => $customer->id, 'date' => $today->toDateString()]))->assertOk()->assertJsonPath($id.'.price', 47.2);
        $this->get(route('prices.list', ['customer' => $customer->id]))->assertOk()->assertSee('VE11258')->assertSee('Topdan');
        $this->get(route('prices.index', ['state' => 'all']))->assertOk()->assertSee('Qaraj');
    }

    public function test_bulk_change_and_excel_import(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->payload());
        $this->post(route('products.store'), $this->payload(['code' => 'VE2', 'barcode' => null, 'extra_barcodes' => null, 'units' => [], 'sale_price' => 20, 'purchase_price' => 10]));
        $base = (int) DB::table('price_lists')->where('name', 'Satış qiyməti (baza)')->value('id');
        $prices = app(PriceService::class);
        $date = now()->addDays(5)->startOfDay();

        $this->post(route('prices.bulk'), ['price_list_id' => $base, 'method' => 'percent', 'value' => 10, 'valid_from' => $date->toDateString(), 'round' => '0.05'])->assertSessionHas('popup');
        $this->assertSame(55.0, $prices->salePrices(null, $date)[Product::query()->where('code', 'VE11258')->value('id')]['price']);
        $this->post(route('prices.bulk'), ['price_list_id' => $base, 'method' => 'markup', 'value' => 50, 'valid_from' => $date->copy()->addDay()->toDateString(), 'q' => 'VE2']);
        $this->assertSame(15.0, $prices->salePrices(null, $date->copy()->addDay())[Product::query()->where('code', 'VE2')->value('id')]['price'], 'alış 10 + 50 %');

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Kod', 'Qiymət'], ['VE11258', 61.5], ['YOXDUR', 5]]);
        $path = tempnam(sys_get_temp_dir(), 'px').'.xlsx';
        (new Xlsx($book))->save($path);
        $later = now()->addDays(20)->toDateString();
        $this->post(route('prices.import'), ['file' => new UploadedFile($path, 'qiymetler.xlsx', null, null, true), 'price_list_id' => $base, 'valid_from' => $later])
            ->assertSessionHas('popup', fn ($p) => str_contains($p['message'], 'YOXDUR'));
        $this->assertSame(61.5, $prices->salePrices(null, Carbon::parse($later))[Product::query()->where('code', 'VE11258')->value('id')]['price']);
    }

    public function test_default_sale_unit_is_used_in_orders_and_converted_to_base_unit(): void
    {
        // 1 qutu = 4 ədəd, satışda susmaya görə qutu
        $this->actingAs($this->admin)->get(route('products.create'))->assertSee('Satışda susmaya görə vahid')->assertSee('Alışda susmaya görə vahid');
        $this->post(route('products.store'), $this->payload())->assertRedirect();
        $product = Product::query()->where('code', 'VE11258')->firstOrFail();
        $box = (int) DB::table('product_units')->where('product_id', $product->id)->value('id');
        $this->get(route('orders.create'))->assertViewHas('products', fn ($p) => $p->firstWhere('code', 'VE11258')['sale_unit'] === $box
            && $p->firstWhere('code', 'VE11258')['units'][0]['factor'] === 4.0);

        $customer = Customer::query()->create(['code' => 'C1', 'name' => 'Qaraj']);
        // 2 qutu × 190 ₼ = 8 ədəd, əsas qiymət 47,5 ₼ (min 40 ödənir)
        $this->post(route('orders.store'), ['date' => now()->toDateString(), 'order_no' => '8001', 'customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->value('id'),
            'lines' => [['product_id' => $product->id, 'unit_id' => $box, 'op_type' => 'sale', 'qty' => 2, 'price' => 190]]])->assertSessionHasNoErrors();
        $item = DB::table('sales_order_items')->first();
        $this->assertEquals([8.0, 47.5, 380.0, 2.0, 190.0, 'qutu', 4.0], [(float) $item->qty, (float) $item->price, (float) $item->amount, (float) $item->unit_qty, (float) $item->unit_price, $item->unit_name, (float) $item->unit_factor]);
        $order = SalesOrder::query()->firstOrFail();
        $this->get(route('orders.edit', $order))->assertViewHas('values', fn ($v) => $v['lines'][0]['unit_id'] === $box && $v['lines'][0]['qty'] === 2.0);
        $this->get(route('orders.show', $order))->assertSee('2 qutu');
        // qutu qiyməti əsas vahiddə min qiymətdən aşağıdırsa (150 / 4 = 37,5 < 40) — xəta
        $this->post(route('orders.store'), ['date' => now()->toDateString(), 'order_no' => '8002', 'customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->value('id'),
            'lines' => [['product_id' => $product->id, 'unit_id' => $box, 'op_type' => 'sale', 'qty' => 1, 'price' => 150]]])->assertSessionHasErrors('lines.0.price');

        // susmaya görə vahidi əsas vahidə qaytarmaq
        $this->put(route('products.update', $product), $this->payload(['sale_unit' => '']))->assertRedirect();
        $this->assertSame(0, DB::table('product_units')->where('product_id', $product->id)->where('is_sale_default', true)->count());
    }

    public function test_order_respects_min_price_max_discount_and_hides_passive_products(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->payload());
        $this->post(route('products.store'), $this->payload(['code' => 'OLD', 'barcode' => null, 'extra_barcodes' => null, 'units' => [], 'is_active' => 0]));
        $id = (int) Product::query()->where('code', 'VE11258')->value('id');
        $customer = Customer::query()->create(['code' => 'C1', 'name' => 'Qaraj']);
        $order = fn (array $line) => ['date' => now()->toDateString(), 'order_no' => '7001', 'customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->value('id'),
            'lines' => [array_merge(['product_id' => $id, 'op_type' => 'sale', 'qty' => 1, 'price' => 50, 'discount' => 0], $line)]];

        $this->post(route('orders.store'), $order(['discount' => 25]))->assertSessionHasErrors(['lines.0.discount', 'lines.0.price']);
        $this->post(route('orders.store'), $order(['price' => 39]))->assertSessionHasErrors('lines.0.price');
        $this->post(route('orders.store'), $order(['discount' => 10]))->assertSessionHasNoErrors();
        $this->get(route('orders.create'))->assertViewHas('products', fn ($p) => $p->pluck('code')->all() === ['VE11258']);
    }
}
