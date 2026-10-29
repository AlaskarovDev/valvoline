<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\AnalyticsRebuilder;
use App\Services\BatchService;
use App\Services\WarehouseTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class WarehouseModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $main;

    private int $van;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $now = now();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->main = (int) Warehouse::query()->where('is_main', true)->value('id');
        $this->van = (int) Warehouse::query()->where('code', 'A2')->value('id');
        $this->product = DB::table('products')->insertGetId(['code' => 'VE1', 'name' => 'MaxLife 4L', 'sale_price' => 50, 'shelf_life_months' => 60, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonths(2)->toDateString(), 'period' => now()->subMonths(2)->format('Y-m'),
            'product_id' => $this->product, 'product_code' => 'VE1', 'qty' => 20, 'unit_cost' => 30, 'total_cost' => 600, 'row_no' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->rebuild();
    }

    private function rebuild(): void
    {
        app(AnalyticsRebuilder::class)->run();
    }

    private function stock(int $warehouse): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $this->product)->where('warehouse_id', $warehouse)->value('qty');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(float $qty, array $overrides = []): array
    {
        return array_merge(['date' => now()->toDateString(), 'from_warehouse_id' => $this->main, 'to_warehouse_id' => $this->van, 'reason' => 'van_load',
            'driver' => 'Elnur', 'vehicle_plate' => '10-AB-123', 'lines' => [['product_id' => $this->product, 'qty' => $qty]]], $overrides);
    }

    public function test_two_step_transfer_with_partial_receipt_and_shortage(): void
    {
        $transit = WarehouseTransferService::transitId();
        $this->actingAs($this->admin)->get(route('transfers.create'))->assertOk()->assertSee('Barkod skaneri');
        $this->post(route('transfers.store'), $this->payload(12))->assertRedirect()->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'AT-000001');
        $transfer = WarehouseTransfer::query()->firstOrFail();
        $this->assertSame('draft', $transfer->status);
        $this->rebuild();
        $this->assertSame(20.0, $this->stock($this->main), 'qaralama stoku dəyişmir');

        $this->post(route('transfers.send', $transfer))->assertRedirect();
        $this->rebuild();
        $this->assertSame([8.0, 12.0, 0.0], [$this->stock($this->main), $this->stock($transit), $this->stock($this->van)]);

        $line = DB::table('warehouse_transfer_lines')->value('id');
        $this->post(route('transfers.receive', $transfer), ['received' => [$line => 10], 'notes' => [$line => '2 ədəd zədəli']])->assertRedirect();
        $this->rebuild();
        $this->assertSame('partial', $transfer->refresh()->status);
        $this->assertSame([10.0, 2.0], [$this->stock($this->van), $this->stock($transit)]);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('2 ədəd zədəli')->assertSee('Fərqi kəsir kimi sil')->assertSee('30,00', false);

        $this->post(route('transfers.shortage', $transfer))->assertRedirect()->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'SA-000001');
        $this->rebuild();
        $this->assertSame(['received', 0.0], [$transfer->refresh()->status, $this->stock($transit)]);
        $this->assertSame('transfer_shortage', DB::table('stock_write_offs')->value('reason'));

        $this->get(route('transfers.show', [$transfer, 'print' => 1]))->assertOk()->assertSee('AT-000001')->assertSee('10-AB-123');
        $this->get(route('transfers.index', ['preset' => 'all']))->assertOk()->assertSee('AT-000001');
        $this->get(route('write-offs.show', DB::table('stock_write_offs')->value('id')))->assertOk()->assertSee('Transfer kəsiri');
    }

    public function test_send_is_blocked_by_reserved_and_insufficient_stock_and_cancel_returns_goods(): void
    {
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->post(route('orders.store'), ['date' => now()->toDateString(), 'order_no' => '1', 'customer_id' => $customer, 'warehouse_id' => $this->main,
            'lines' => [['product_id' => $this->product, 'op_type' => 'sale', 'qty' => 15, 'price' => 50]]])->assertRedirect();

        $this->post(route('transfers.store'), $this->payload(10, ['then' => 'send']))->assertSessionHas('popup', fn ($p) => str_contains($p['message'], 'VE1'));
        $this->assertSame('draft', WarehouseTransfer::query()->value('status'), 'rezervdən sonra sərbəst 5-dir');

        $this->post(route('transfers.store'), $this->payload(4, ['then' => 'send']));
        $sent = WarehouseTransfer::query()->where('status', 'sent')->firstOrFail();
        $this->rebuild();
        $this->assertSame(16.0, $this->stock($this->main));
        $this->post(route('transfers.cancel', $sent))->assertRedirect();
        $this->rebuild();
        $this->assertSame(['cancelled', 20.0], [$sent->refresh()->status, $this->stock($this->main)]);

        $this->post(route('transfers.store'), $this->payload(3, ['then' => 'complete']));
        $this->rebuild();
        $this->assertSame([17.0, 3.0], [$this->stock($this->main), $this->stock($this->van)]);
        $this->assertSame('received', WarehouseTransfer::query()->latest('id')->value('status'));
    }

    public function test_refill_suggestion_uses_van_sales(): void
    {
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->post(route('transfers.store'), $this->payload(5, ['then' => 'complete']));
        $this->post(route('orders.store'), ['date' => now()->toDateString(), 'order_no' => '2', 'customer_id' => $customer, 'warehouse_id' => $this->van, 'then' => 'send',
            'lines' => [['product_id' => $this->product, 'op_type' => 'sale', 'qty' => 4, 'price' => 50]]]);
        $this->rebuild();
        $this->assertSame(1.0, $this->stock($this->van));
        // son 14 gündə 4 satılıb, qalıq 1 → 3 tövsiyə
        $this->getJson(route('transfers.suggest', ['from' => $this->main, 'to' => $this->van]))->assertOk()->assertJsonPath('0.qty', 3);
    }

    public function test_batches_fefo_expiry_write_off_and_sale_block(): void
    {
        $this->actingAs($this->admin)->get(route('batches.index', ['tab' => 'all']))->assertOk()->assertSee('P-1')->assertSee('təxmini');

        $this->post(route('batches.store'), ['product_id' => $this->product, 'batch_no' => 'L-OLD', 'production_date' => now()->subYears(6)->toDateString(), 'qty' => 5])->assertRedirect();
        $this->post(route('batches.store'), ['product_id' => $this->product, 'batch_no' => 'L-NEW', 'expiry_date' => now()->addYears(4)->toDateString(), 'qty' => 17])->assertRedirect();
        $old = DB::table('product_batches')->where('batch_no', 'L-OLD')->first();
        $this->assertSame([now()->subYears(6)->addMonths(60)->toDateString(), 1], [$old->expiry_date, (int) $old->expiry_estimated], 'istehsal + 60 ay');

        // stok 20: FEFO — L-NEW 17, L-OLD 3 (vaxtı bitib)
        $rows = app(BatchService::class)->batches()->keyBy('batch_no');
        $this->assertSame([17.0, 3.0, 'expired'], [$rows['L-NEW']->remaining, $rows['L-OLD']->remaining, $rows['L-OLD']->state]);
        $this->get(route('batches.index'))->assertOk()->assertViewHas('rows', fn ($r) => collect($r->items())->pluck('batch_no')->all() === ['L-OLD']);

        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $order = fn ($qty) => ['date' => now()->toDateString(), 'order_no' => '9'.$qty, 'customer_id' => $customer, 'warehouse_id' => $this->main,
            'lines' => [['product_id' => $this->product, 'op_type' => 'sale', 'qty' => $qty, 'price' => 50]]];
        $this->post(route('orders.store'), $order(18))->assertSessionHasErrors('lines.0.qty');
        $this->post(route('orders.store'), $order(17))->assertSessionHasNoErrors();

        $this->post(route('batches.write-off'), ['warehouse_id' => $this->main, 'items' => [$this->product.':'.$old->id.':3']])->assertRedirect()->assertSessionHas('popup');
        $this->rebuild();
        $this->assertSame(17.0, (float) DB::table('products')->where('id', $this->product)->value('stock_qty'));
        $this->assertSame(0, app(BatchService::class)->batches()->where('state', 'expired')->count());
        $this->get(route('write-offs.show', [DB::table('stock_write_offs')->value('id'), 'print' => 1]))->assertOk()->assertSee('L-OLD')->assertSee('Malın silinməsi aktı');

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Kod', 'Partiya', 'İstehsal', 'Son istifadə', 'Miqdar'], ['VE1', 'L-XL', '2026-01-01', '2031-01-01', 10]]);
        $path = tempnam(sys_get_temp_dir(), 'bt').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->post(route('batches.import'), ['file' => new UploadedFile($path, 'partiyalar.xlsx', null, null, true)])->assertSessionHas('popup');
        $this->assertTrue(DB::table('product_batches')->where('batch_no', 'L-XL')->where('expiry_date', '2031-01-01')->exists());
    }

    public function test_warehouses_page_and_van_creation(): void
    {
        $manager = DB::table('managers')->insertGetId(['name' => 'Elnur', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->get(route('warehouses.index'))->assertOk()->assertSee('Yolda (tranzit)');
        $this->post(route('warehouses.store'), ['name' => 'Maşın 3', 'type' => 'van', 'vehicle_plate' => '90-XY-001', 'manager_id' => $manager, 'is_active' => 1])->assertRedirect();
        $this->assertTrue(Warehouse::query()->where('name', 'Maşın 3')->where('type', 'van')->where('manager_id', $manager)->exists());
        $this->get(route('orders.create'))->assertViewHas('warehouses', fn ($w) => ! $w->pluck('name')->contains('Yolda (tranzit)') && $w->pluck('name')->contains('Maşın 3'));
    }
}
