<?php

namespace Tests\Feature;

use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use App\Services\FinanceService;
use App\Services\SupplierLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alış: təchizatçı, valyutalı mədaxil (Mərkəzi Bank məzənnəsi), əlavə xərcin mayaya paylanması, qaytarma, kreditor borcu.
 */
class PurchasingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $oil;

    private int $exempt;

    private int $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Yağ 4L', 'vat_mode' => 'standard', 'vat_rate' => 18, 'created_at' => now(), 'updated_at' => now()]);
        $this->exempt = DB::table('products')->insertGetId(['code' => 'EXM', 'name' => 'Azad mal', 'vat_mode' => 'exempt', 'vat_rate' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        Http::fake(['www.cbar.az/*' => Http::response('<?xml version="1.0" encoding="UTF-8"?><ValCurs Date="'.now()->format('d.m.Y').'" Name="x"><ValType Type="Xarici valyutalar"><Valute Code="USD"><Nominal>1</Nominal><Name>1 ABŞ dolları</Name><Value>1.7</Value></Valute><Valute Code="RUB"><Nominal>100</Nominal><Name>100 Rusiya rublu</Name><Value>2.05</Value></Valute></ValType></ValCurs>')]);
    }

    private function stock(int $product): array
    {
        app(AnalyticsRebuilder::class)->run();

        return [(float) DB::table('stock_balances')->where('product_id', $product)->where('warehouse_id', $this->wh)->value('qty'),
            round((float) DB::table('products')->where('id', $product)->value('current_avg_cost'), 4)];
    }

    public function test_receipt_in_foreign_currency_with_landed_costs_return_and_supplier_debt(): void
    {
        $this->actingAs($this->admin)->post(route('suppliers.store'), ['name' => 'Valvoline Europe', 'currency' => 'USD', 'country' => 'Niderland', 'payment_days' => 30])
            ->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'T0001');
        $supplier = Supplier::query()->firstOrFail();

        // Mərkəzi Bank: 1 USD = 1.7; 100 RUB = 2.05 → 0.0205
        $this->getJson(route('purchase-docs.rate', ['code' => 'USD', 'date' => now()->toDateString()]))->assertJson(['rate' => 1.7]);
        $this->getJson(route('purchase-docs.rate', ['code' => 'RUB', 'date' => now()->toDateString()]))->assertJson(['rate' => 0.0205]);
        $this->get(route('purchase-docs.create', ['supplier' => $supplier->id]))->assertOk()->assertSee('Gömrük xərci');

        // 10 × 5 USD (18 %) + 5 × 10 USD (azad); gömrük 17 ₼ dəyər nisbətində 8.5 / 8.5
        $this->post(route('purchase-docs.store'), ['type' => 'receipt', 'date' => now()->toDateString(), 'supplier_id' => $supplier->id, 'warehouse_id' => $this->wh,
            'rate' => 1.7, 'customs_cost' => 17, 'supplier_invoice_no' => 'INV-77',
            'lines' => [['product_id' => $this->oil, 'qty' => 10, 'price' => 5], ['product_id' => $this->exempt, 'qty' => 5, 'price' => 10]]])
            ->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'MD-000001');
        $receipt = PurchaseDocument::query()->firstOrFail();
        $this->assertSame([100.0, 9.0, 109.0, 187.0], [(float) $receipt->goods_amount, (float) $receipt->vat_amount, (float) $receipt->total_amount, (float) $receipt->total_cost_azn]);
        $this->assertSame(now()->addDays(30)->toDateString(), $receipt->due_date->toDateString());
        $this->assertSame([10.0, 9.35], $this->stock($this->oil));
        $this->assertSame([5.0, 18.7], $this->stock($this->exempt));
        $this->assertSame(109.0, app(SupplierLedger::class)->balances()[$supplier->id]['balance']);
        $this->get(route('purchase-docs.show', $receipt))->assertOk()->assertSee('INV-77')->assertSee('9,3500', false);
        $this->get(route('purchase-docs.show', [$receipt, 'print' => 1]))->assertOk()->assertSee('Mədaxil qaiməsi № MD-000001');

        // qaytarma: 2 ədəd yağ → borc 2 × 5 × 1.18 = 11.8 USD azalır
        $this->get(route('purchase-docs.create', ['type' => 'return', 'from' => $receipt->id]))->assertOk()->assertSee('MD-000001');
        $this->post(route('purchase-docs.store'), ['type' => 'return', 'date' => now()->toDateString(), 'supplier_id' => $supplier->id, 'warehouse_id' => $this->wh,
            'rate' => 1.7, 'original_document_id' => $receipt->id, 'lines' => [['product_id' => $this->oil, 'qty' => 2, 'price' => 5]]])
            ->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'TQ-000001');
        $this->assertSame(8.0, $this->stock($this->oil)[0]);
        $this->assertSame(97.2, app(SupplierLedger::class)->balances()[$supplier->id]['balance']);
        $this->get(route('suppliers.show', $supplier))->assertOk()->assertSee('97,20', false)->assertSee('TQ-000001');
        $this->get(route('suppliers.index'))->assertOk()->assertSee('Valvoline Europe')->assertSee('97,20 USD', false);

        // qaytarması olan mədaxil ləğv edilmir; əvvəl qaytarma, sonra mədaxil
        $this->delete(route('purchase-docs.destroy', $receipt), ['reason' => 'səhv'])->assertSessionHas('error');
        $return = PurchaseDocument::query()->where('type', 'return')->firstOrFail();
        $this->delete(route('purchase-docs.destroy', $return), ['reason' => 'səhv'])->assertSessionHas('popup');
        $this->delete(route('purchase-docs.destroy', $receipt), ['reason' => 'səhv'])->assertSessionHas('popup');
        $this->assertSame(0.0, $this->stock($this->oil)[0]);
        $this->assertSame(0.0, app(SupplierLedger::class)->balances()[$supplier->id]['balance'] ?? 0.0);
    }

    public function test_foreign_currency_needs_a_rate_and_access_is_limited(): void
    {
        Http::fake(['www.cbar.az/*' => Http::response('', 500)]);
        $supplier = Supplier::query()->create(['code' => 'T1', 'name' => 'Türk təchizatçı', 'currency' => 'TRY']);
        $this->actingAs($this->admin)->post(route('purchase-docs.store'), ['type' => 'receipt', 'date' => now()->toDateString(), 'supplier_id' => $supplier->id,
            'warehouse_id' => $this->wh, 'lines' => [['product_id' => $this->oil, 'qty' => 1, 'price' => 5]]])->assertSessionHasErrors('rate');
        $this->assertSame(0, PurchaseDocument::query()->count());

        $rep = User::factory()->create(['role' => 'menecer']);
        $this->actingAs($rep)->get(route('purchase-docs.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'anbardar']))->get(route('purchase-docs.index'))->assertOk();
    }

    public function test_supplier_payments_from_bank_and_cash_reduce_debt(): void
    {
        $this->actingAs($this->admin);
        $usd = Supplier::query()->create(['code' => 'T1', 'name' => 'Valvoline Europe', 'currency' => 'USD']);
        $local = Supplier::query()->create(['code' => 'T2', 'name' => 'Yerli Qablaşdırma', 'currency' => 'AZN']);
        foreach ([[$usd, 1.7], [$local, 1]] as [$s, $rate]) {
            $this->post(route('purchase-docs.store'), ['type' => 'receipt', 'date' => now()->toDateString(), 'supplier_id' => $s->id, 'warehouse_id' => $this->wh,
                'rate' => $rate, 'lines' => [['product_id' => $this->exempt, 'qty' => 10, 'price' => 10]]])->assertSessionHas('popup');
        }
        $account = DB::table('bank_accounts')->insertGetId(['name' => 'Kapital AZN', 'currency' => 'AZN', 'kind' => 'bank', 'created_at' => now(), 'updated_at' => now()]);
        $workplace = (int) DB::table('workplaces')->value('id');

        // AZN hesabdan USD təchizatçıya: 85 ₼ ödənilir, borcdan 50 USD silinir
        $this->get(route('supplier-payments.create', ['supplier' => $usd->id]))->assertOk()->assertSee('Borcdan silinən məbləğ');
        $pay = ['date' => now()->toDateString(), 'workplace_id' => $workplace, 'supplier_id' => $usd->id, 'source' => 'bank', 'bank_account_id' => $account, 'amount' => 85];
        $this->post(route('supplier-payments.store'), $pay)->assertSessionHasErrors('to_amount');
        $this->post(route('supplier-payments.store'), $pay + ['to_amount' => 50])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'TO-000001');
        $this->assertSame(50.0, app(SupplierLedger::class)->balances()[$usd->id]['balance']);
        $this->assertSame(85.0, (float) DB::table('bank_transactions')->where('bank_account_id', $account)->sum('amount_out'));

        // kassadan: kifayət qədər pul olmalıdır
        $register = (int) DB::table('cash_registers')->where('code', 'KS-001')->value('id');
        $cash = ['date' => now()->toDateString(), 'workplace_id' => $workplace, 'supplier_id' => $local->id, 'source' => 'cash', 'cash_register_id' => $register, 'amount' => 40];
        $this->post(route('supplier-payments.store'), $cash)->assertSessionHasErrors('amount');
        $this->post(route('finance.documents.store'), ['type' => 'cash_opening', 'date' => now()->subDay()->toDateString(), 'workplace_id' => $workplace, 'cash_register_id' => $register, 'amount' => 100]);
        $this->post(route('supplier-payments.store'), $cash)->assertSessionHas('popup');
        $this->assertSame(60.0, (float) app(FinanceService::class)->cashLedger($register)->last()->balance);
        $this->assertSame(60.0, app(SupplierLedger::class)->balances()[$local->id]['balance']);
        $this->get(route('suppliers.show', $local))->assertSee('Ödəniş')->assertSee('60,00', false);

        // ləğv edilən ödəniş borca qayıdır
        $doc = DB::table('finance_documents')->where('type', 'supplier_payment')->where('supplier_id', $local->id)->first();
        $this->delete(route('finance.documents.destroy', $doc->id), ['reason' => 'səhv'])->assertSessionHas('popup');
        $this->assertSame(100.0, app(SupplierLedger::class)->balances()[$local->id]['balance']);
        $this->get(route('finance.documents.create', ['type' => 'supplier_payment']))->assertRedirect(route('supplier-payments.create'));
    }
}
