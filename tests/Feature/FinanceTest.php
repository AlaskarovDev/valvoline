<?php

namespace Tests\Feature;

use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $workplace;

    private int $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->workplace = (int) DB::table('workplaces')->where('code', 'IY-01')->value('id');
        $this->main = (int) DB::table('cash_registers')->where('code', 'KS-001')->value('id');
    }

    private function balance(int $register): float
    {
        return (float) optional(app(FinanceService::class)->cashLedger($register)->last())->balance;
    }

    private function doc(string $type, array $data)
    {
        return $this->post(route('finance.documents.store'), array_merge(['type' => $type, 'date' => now()->toDateString(), 'workplace_id' => $this->workplace], $data));
    }

    public function test_rep_with_own_register_receives_customer_payments_and_transfers(): void
    {
        $this->actingAs($this->admin)->post(route('reps.store'), ['name' => 'Elnur Ekspeditor', 'role' => 'forwarder', 'workplace_id' => $this->workplace, 'phone' => '+994501112233', 'create_register' => 1, 'is_active' => 1])->assertRedirect();
        $rep = DB::table('managers')->where('name', 'Elnur Ekspeditor')->first();
        $register = DB::table('cash_registers')->where('manager_id', $rep->id)->first();
        $this->assertSame(['SN-001', 'KS-002'], [$rep->code, $register->code]);
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'manager_id' => $rep->id, 'created_at' => now(), 'updated_at' => now()]);

        // açılış: 50; əvvəlki import ödənişi nəzərə alınmır, sonrakı alınır
        DB::table('payments')->insert(['row_no' => 1, 'date' => now()->subDays(10)->toDateString(), 'period' => now()->format('Y-m'), 'customer_id' => $customer, 'manager_id' => $rep->id, 'cash' => 999, 'total' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->doc('cash_opening', ['cash_register_id' => $register->id, 'amount' => 50, 'date' => now()->subDays(5)->toDateString()])->assertRedirect()->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'KA-000001');
        DB::table('payments')->insert(['row_no' => 2, 'date' => now()->subDays(2)->toDateString(), 'period' => now()->format('Y-m'), 'customer_id' => $customer, 'manager_id' => $rep->id, 'cash' => 30, 'total' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(80.0, $this->balance($register->id));

        // müştəri ödənişi → nümayəndənin kassası, borc azalır
        $this->doc('cash_receipt', ['cash_register_id' => $register->id, 'customer_id' => $customer, 'amount' => 100, 'note' => 'Qəbz 15'])
            ->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'KM-000001');
        $receipt = DB::table('finance_documents')->where('type', 'cash_receipt')->first();
        $payment = DB::table('payments')->find($receipt->payment_id);
        $this->assertSame([100.0, $rep->id, $register->id], [(float) $payment->cash, (int) $payment->manager_id, (int) $payment->cash_register_id]);
        $this->assertSame(180.0, $this->balance($register->id));
        app(AnalyticsRebuilder::class)->run();
        $this->assertEqualsWithDelta(1129.0, (float) DB::table('customer_balances')->where('customer_id', $customer)->value('payments_total'), 0.001);

        // kassa transferi: kifayət etməyən məbləğ rədd, sonra əsas kassaya 120
        $this->doc('cash_transfer', ['cash_register_id' => $register->id, 'to_cash_register_id' => $this->main, 'amount' => 500])->assertSessionHasErrors('amount');
        $this->doc('cash_transfer', ['cash_register_id' => $register->id, 'to_cash_register_id' => $this->main, 'amount' => 120])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'KT-000001');
        $this->assertSame([60.0, 120.0], [$this->balance($register->id), $this->balance($this->main)]);

        $this->get(route('finance.registers'))->assertOk()->assertSee('KS-002')->assertSee('Elnur Ekspeditor');
        $this->get(route('finance.register', $register->id))->assertOk()->assertSee('KM-000001')->assertSee('Qaraj');
        $this->get(route('finance.documents', ['preset' => 'all']))->assertOk()->assertSee('KT-000001')->assertSee('Mərkəzi ofis');
        $this->get(route('finance.documents.show', [$receipt->id, 'print' => 1]))->assertOk()->assertSee('KM-000001')->assertSee('Mərkəzi ofis');
        $this->get(route('reps.index'))->assertOk()->assertSee('SN-001')->assertSee('KS-002');

        // ləğv: ödəniş də silinir
        $this->delete(route('finance.documents.destroy', $receipt->id))->assertRedirect();
        $this->assertNull(DB::table('payments')->find($receipt->payment_id));
    }

    public function test_banks_accounts_opening_and_cross_currency_transfer(): void
    {
        $this->actingAs($this->admin)->post(route('finance.banks.store'), ['name' => 'Kapital Bank', 'swift' => 'AIIBAZ2X'])->assertRedirect();
        $bank = DB::table('banks')->where('name', 'Kapital Bank')->value('id');
        foreach (['AZN', 'USD', 'RUB'] as $currency) {
            $this->post(route('finance.accounts.store'), ['bank_id' => $bank, 'currency' => $currency])->assertRedirect();
        }
        $azn = DB::table('bank_accounts')->where('name', 'Kapital Bank AZN')->value('id');
        $usd = DB::table('bank_accounts')->where('name', 'Kapital Bank USD')->value('id');
        $this->doc('bank_opening', ['bank_account_id' => $azn, 'amount' => 1000])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'BA-000001');

        $this->doc('bank_transfer', ['bank_account_id' => $azn, 'to_bank_account_id' => $usd, 'amount' => 170])->assertSessionHasErrors('to_amount');
        $this->doc('bank_transfer', ['bank_account_id' => $azn, 'to_bank_account_id' => $usd, 'amount' => 170, 'to_amount' => 100])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'BT-000001');
        $finance = app(FinanceService::class);
        $this->assertSame([830.0, 100.0], [$finance->bankBalance($azn)['balance'], $finance->bankBalance($usd)['balance']]);
        $this->assertEqualsWithDelta(0.588235, (float) DB::table('finance_documents')->where('type', 'bank_transfer')->value('rate'), 0.000001);

        $this->get(route('finance.banks'))->assertOk()->assertSee('Kapital Bank RUB')->assertSee('830,00', false)->assertSee('AIIBAZ2X');
        $doc = DB::table('finance_documents')->where('type', 'bank_transfer')->value('id');
        $this->get(route('finance.documents.show', $doc))->assertOk()->assertSee('Kapital Bank USD');
        $this->delete(route('finance.documents.destroy', $doc));
        $this->assertSame(1000.0, $finance->bankBalance($azn)['balance']);
    }

    public function test_every_document_carries_a_workplace(): void
    {
        $second = DB::table('workplaces')->insertGetId(['code' => 'IY-02', 'name' => 'Gəncə filialı', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->get(route('workplaces.index'))->assertOk()->assertSee('Gəncə filialı');
        $this->put(route('workplaces.assign'), ['users' => [$this->admin->id => $second]])->assertRedirect();
        $this->admin->refresh();

        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['code' => 'P1', 'name' => 'Yağ', 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('orders.create'))->assertViewHas('values', fn ($v) => $v['workplace_id'] === $second);
        $this->post(route('orders.store'), ['date' => now()->toDateString(), 'order_no' => '1', 'customer_id' => $customer, 'warehouse_id' => Warehouse::query()->value('id'),
            'then' => 'invoice', 'lines' => [['product_id' => $product, 'op_type' => 'sale', 'qty' => 1, 'price' => 10]]])->assertRedirect();
        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame([$second, $second, $second], [$order->workplace_id, (int) DB::table('delivery_notes')->value('workplace_id'), (int) DB::table('sales_invoices')->value('workplace_id')]);
        $this->get(route('invoices.show', [DB::table('sales_invoices')->value('id'), 'print' => 1]))->assertOk()->assertSee('Gəncə filialı');

        $this->post(route('transfers.store'), ['date' => now()->toDateString(), 'from_warehouse_id' => Warehouse::query()->where('is_main', true)->value('id'),
            'to_warehouse_id' => Warehouse::query()->where('code', 'A2')->value('id'), 'reason' => 'replenish', 'workplace_id' => $this->workplace, 'lines' => [['product_id' => $product, 'qty' => 1]]]);
        $this->assertSame($this->workplace, (int) DB::table('warehouse_transfers')->value('workplace_id'));
    }
}
