<?php

namespace Tests\Feature;

use App\Models\StockDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $van;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->van = (int) Warehouse::query()->where('code', 'A2')->value('id');
        $this->product = DB::table('products')->insertGetId(['code' => 'VE1', 'name' => 'MaxLife 4L', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stock(): float
    {
        app(AnalyticsRebuilder::class)->run();

        return (float) DB::table('stock_balances')->where('product_id', $this->product)->where('warehouse_id', $this->van)->value('qty');
    }

    private function doc(string $type, array $line, array $extra = [])
    {
        return $this->post(route('stock-docs.store'), array_merge(['type' => $type, 'date' => now()->toDateString(), 'warehouse_id' => $this->van,
            'lines' => [array_merge(['product_id' => $this->product], $line)]], $extra));
    }

    public function test_opening_count_loss_and_consumption_documents_move_stock(): void
    {
        $this->actingAs($this->admin)->get(route('stock-docs.create', ['type' => 'opening']))->assertOk()->assertSee('Açılış sənədi');
        $this->doc('opening', ['qty' => 10, 'unit_cost' => 25])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'AC-000001');
        $this->assertSame(10.0, $this->stock());
        $this->assertEqualsWithDelta(25.0, (float) DB::table('products')->where('id', $this->product)->value('current_avg_cost'), 0.001, 'açılış mayası');

        // sayım: sayılan 13, sistemdə 10 → artıq 3
        $this->doc('count_surplus', ['counted_qty' => 13])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'SAR-000001');
        $line = DB::table('stock_document_lines')->latest('id')->first();
        $this->assertEquals([10.0, 13.0, 3.0], [(float) $line->system_qty, (float) $line->counted_qty, (float) $line->qty]);
        $this->assertSame(13.0, $this->stock());
        // işarə uyğun deyil: artıq sənədində sayılan sistemdən azdır
        $this->doc('count_surplus', ['counted_qty' => 9])->assertSessionHasErrors('lines');
        $this->doc('count_shortage', ['counted_qty' => 11])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'SAS-000001');
        $this->assertSame(11.0, $this->stock());

        $this->doc('production_loss', ['qty' => 1, 'note' => 'dağılıb'])->assertSessionHas('popup');
        $this->doc('consumption', ['qty' => 2], ['purpose' => 'Nümunə (müştəriyə)'])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'SRF-000001');
        $this->assertSame(8.0, $this->stock());

        $consumption = StockDocument::query()->where('type', 'consumption')->firstOrFail();
        $this->get(route('stock-docs.show', $consumption))->assertOk()->assertSee('Nümunə (müştəriyə)')->assertSee('25,00', false);
        $this->get(route('stock-docs.show', [$consumption, 'print' => 1]))->assertOk()->assertSee('Sərf sənədi № SRF-000001');
        $this->get(route('stock-docs.index', ['type' => 'count_surplus']))->assertOk()->assertSee('SAR-000001')->assertDontSee('SAS-000001');
        $this->get(route('stock-docs.index'))->assertOk()->assertSee('SAS-000001')->assertSee('AC-000001');

        $this->delete(route('stock-docs.destroy', $consumption))->assertRedirect();
        $this->assertSame(10.0, $this->stock());
    }

    /**
     * Menyuda yalnız bir bənd işıqlanır: məs. «Sayım əskiyi» səhifəsində «Anbar qalığı» işıqlanmamalıdır.
     */
    public function test_only_the_current_menu_item_is_highlighted(): void
    {
        $this->actingAs($this->admin);
        $this->doc('opening', ['qty' => 5, 'unit_cost' => 10]);
        $this->doc('count_shortage', ['counted_qty' => 4])->assertSessionHas('popup');
        $doc = StockDocument::query()->where('type', 'count_shortage')->firstOrFail();
        $cases = [
            [route('stock-docs.create', ['type' => 'count_shortage']), 'Sayım əskiyi'],
            [route('stock-docs.index', ['type' => 'count_shortage']), 'Sayım əskiyi'],
            [route('stock-docs.index'), 'Bütün stok sənədləri'],
            [route('stock.index'), 'Anbar qalığı və dəyəri'],
            [route('prices.list'), 'Qiymət siyahısı'],
            [route('prices.index'), 'Qiymət kartları'],
            [route('stock-docs.show', $doc), 'Sayım əskiyi'],
        ];
        foreach ($cases as [$url, $label]) {
            $html = $this->get($url)->assertOk()->getContent();
            preg_match_all('/<a [^>]*aria-current="page"[^>]*>.*?<span>([^<]+)<\/span>/s', $html, $m);
            $this->assertSame([$label], $m[1], $url);
        }
    }

    public function test_menu_has_master_data_and_documents_subsections_and_customer_prices_page(): void
    {
        $this->actingAs($this->admin)->get(route('products.index'))->assertOk()
            ->assertSee('nav-subsection', false)->assertSee('Əsas məlumat')->assertSee('Sayım artığı')->assertSee('Müştəriyə özəl qiymətlər');

        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $base = DB::table('price_lists')->where('type', 'sale')->where('is_default', true)->value('id');
        $this->post(route('prices.store'), ['product_id' => $this->product, 'type' => 'sale', 'price_list_id' => $base, 'price' => 50, 'vat_included' => 1, 'valid_from' => now()->toDateString()]);
        $this->post(route('prices.store'), ['product_id' => $this->product, 'type' => 'sale', 'customer_id' => $customer, 'price' => 44, 'vat_included' => 1, 'valid_from' => now()->toDateString()]);
        $this->get(route('prices.index'))->assertOk()->assertSee('50,00', false)->assertDontSee('Qaraj');
        $this->get(route('customer-prices.index'))->assertOk()->assertSee('Qaraj')->assertSee('44,00', false)->assertDontSee('Toplu qiymət dəyişikliyi');
    }
}
