<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\StockDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AnalyticsRebuilder;
use App\Services\FinanceService;
use App\Services\PeriodLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sənəd bütövlüyü: audit jurnalı, silmək əvəzinə ləğv, dövr bağlama.
 */
class DocumentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $van;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Baş Admin']);
        $this->van = (int) Warehouse::query()->where('code', 'A2')->value('id');
        $this->product = DB::table('products')->insertGetId(['code' => 'VE1', 'name' => 'MaxLife 4L', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stockDoc(string $type, array $line, string $date)
    {
        return $this->post(route('stock-docs.store'), ['type' => $type, 'date' => $date, 'warehouse_id' => $this->van,
            'lines' => [array_merge(['product_id' => $this->product], $line)]]);
    }

    private function stock(): float
    {
        app(AnalyticsRebuilder::class)->run();

        return (float) DB::table('stock_balances')->where('product_id', $this->product)->where('warehouse_id', $this->van)->value('qty');
    }

    public function test_every_change_is_written_to_the_audit_log_with_who_what_and_diff(): void
    {
        $this->actingAs($this->admin);
        $this->stockDoc('opening', ['qty' => 10, 'unit_cost' => 5], now()->toDateString())->assertSessionHas('popup');
        $log = AuditLog::query()->where('action', 'stock-docs.store')->firstOrFail();
        $this->assertSame(['Baş Admin', 'ok', '|AC-000001|'], [$log->user_name, $log->outcome, $log->doc_nos]);
        $this->assertSame('Stok sənədi — yaradıldı', $log->label());

        // dəyişiklik: əvvəlki və sonrakı dəyər saxlanılır
        $warehouse = Warehouse::query()->findOrFail($this->van);
        $this->put(route('warehouses.update', $warehouse), ['name' => 'Səyyar maşın 2', 'code' => $warehouse->code, 'type' => 'van', 'is_active' => 1])->assertSessionHasNoErrors();
        $change = AuditLog::query()->where('action', 'warehouses.update')->firstOrFail();
        $this->assertSame(['Warehouse', $this->van], [$change->subject_type, $change->subject_id]);
        $this->assertSame(['from' => $warehouse->name, 'to' => 'Səyyar maşın 2'], $change->payload['changes']['name']);

        // validasiya xətası da yazılır (nəticə: alınmadı)
        $this->stockDoc('opening', ['qty' => 1], now()->toDateString())->assertSessionHasErrors();
        $this->assertSame('error', AuditLog::query()->where('action', 'stock-docs.store')->latest('id')->value('outcome'));

        // sənəd səhifəsində tarixçə, admin üçün jurnal və detal
        $doc = StockDocument::query()->firstOrFail();
        $this->get(route('stock-docs.show', $doc))->assertOk()->assertSee('Tarixçə')->assertSee('Stok sənədi — yaradıldı');
        $this->get(route('audit.index'))->assertOk()->assertSee('Stok sənədi — yaradıldı')->assertSee('Anbar — dəyişdirildi');
        $this->get(route('audit.index', ['q' => 'AC-000001']))->assertOk()->assertSee('Stok sənədi — yaradıldı')->assertDontSee('Anbar — dəyişdirildi');
        $this->get(route('audit.show', $change))->assertOk()->assertSee('Dəyişən sahələr')->assertSee('Səyyar maşın 2');

        $manager = User::factory()->create(['role' => 'menecer']);
        $this->actingAs($manager)->get(route('audit.index'))->assertForbidden();
    }

    public function test_login_attempts_are_logged_without_the_password(): void
    {
        $this->post(route('login'), ['email' => $this->admin->email, 'password' => 'yanlış-parol-123']);
        $failed = AuditLog::query()->where('action', 'auth.login')->latest('id')->firstOrFail();
        $this->assertSame(['error', $this->admin->email], [$failed->outcome, $failed->user_name]);
        $this->assertStringNotContainsString('yanlış-parol-123', json_encode($failed->payload));
    }

    public function test_cancelled_documents_stay_with_number_but_lose_their_effect(): void
    {
        $this->actingAs($this->admin);
        $this->stockDoc('opening', ['qty' => 10, 'unit_cost' => 5], now()->toDateString());
        $this->stockDoc('consumption', ['qty' => 4], now()->toDateString());
        $this->assertSame(6.0, $this->stock());

        $consumption = StockDocument::query()->where('type', 'consumption')->firstOrFail();
        $this->delete(route('stock-docs.destroy', $consumption), ['reason' => 'Təkrar yazılıb'])->assertRedirect(route('stock-docs.show', $consumption));
        $this->assertSame(10.0, $this->stock());
        $consumption->refresh();
        $this->assertSame(['Təkrar yazılıb', 1], [$consumption->cancel_reason, DB::table('stock_document_lines')->where('stock_document_id', $consumption->id)->count()]);
        $this->get(route('stock-docs.show', $consumption))->assertSee('Bu sənəd ləğv edilib')->assertSee('Təkrar yazılıb')->assertDontSee('cancelDialog', false);
        $this->get(route('stock-docs.show', [$consumption, 'print' => 1]))->assertSee('LƏĞV EDİLİB');
        $this->get(route('stock-docs.index'))->assertDontSee('SRF-000001');
        $this->get(route('stock-docs.index', ['state' => 'cancelled']))->assertSee('SRF-000001');
        $this->delete(route('stock-docs.destroy', $consumption))->assertSessionHas('error', 'Bu sənəd artıq ləğv edilib.');

        // ləğv edilmiş açılış sənədi maya / stok hesablamasından çıxır; yeni sənəd yeni nömrə alır
        $opening = StockDocument::query()->where('type', 'opening')->firstOrFail();
        $this->delete(route('stock-docs.destroy', $opening), ['reason' => 'Səhv anbar']);
        $this->assertSame(0.0, $this->stock());
        $this->stockDoc('opening', ['qty' => 3, 'unit_cost' => 5], now()->toDateString())->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'AC-000002');

        // maliyyə sənədi: ləğvdə ödəniş götürülür, kassa qalığına düşmür
        $workplace = (int) DB::table('workplaces')->where('code', 'IY-01')->value('id');
        $register = (int) DB::table('cash_registers')->where('code', 'KS-001')->value('id');
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('finance.documents.store'), ['type' => 'cash_receipt', 'date' => now()->toDateString(), 'workplace_id' => $workplace,
            'cash_register_id' => $register, 'customer_id' => $customer, 'amount' => 70])->assertSessionHas('popup');
        $receipt = DB::table('finance_documents')->where('type', 'cash_receipt')->first();
        $this->delete(route('finance.documents.destroy', $receipt->id), ['reason' => 'Səhv müştəri']);
        $this->assertNotNull(DB::table('finance_documents')->where('id', $receipt->id)->value('cancelled_at'));
        $this->assertSame(0, DB::table('payments')->where('finance_document_id', $receipt->id)->count());
        $this->assertSame(0.0, (float) optional(app(FinanceService::class)->cashLedger($register)->last())->balance);
    }

    public function test_locked_period_rejects_new_documents_and_cancellations(): void
    {
        $this->actingAs($this->admin);
        $old = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->stockDoc('opening', ['qty' => 10, 'unit_cost' => 5], $old)->assertSessionHas('popup');
        $opening = StockDocument::query()->firstOrFail();

        $this->put(route('period-lock.update'), ['locked_until' => now()->subMonthNoOverflow()->endOfMonth()->toDateString()])->assertSessionHas('popup');
        $this->assertTrue(PeriodLock::isLocked($old));
        $this->get(route('period-lock.index'))->assertOk()->assertSee('Bağlıdır');

        // bağlı tarixə yeni sənəd yazılmır, köhnə sənəd ləğv edilmir
        $this->stockDoc('consumption', ['qty' => 1], $old)->assertSessionHasErrors('lines');
        $this->assertStringStartsWith('Dövr bağlanıb: '.now()->subMonthNoOverflow()->endOfMonth()->format('d.m.Y'), session('errors')->first('lines'));
        $this->assertSame(1, StockDocument::query()->count());
        $this->delete(route('stock-docs.destroy', $opening), ['reason' => 'test'])->assertSessionHas('error');
        $this->assertNull($opening->refresh()->cancelled_at);
        // açıq tarixə yazılır
        $this->stockDoc('consumption', ['qty' => 1], now()->toDateString())->assertSessionHas('popup');

        // dövr açılanda yenidən mümkündür; bağlama da jurnala düşür
        $this->put(route('period-lock.update'), ['unlock' => 1])->assertSessionHas('popup');
        $this->assertNull(PeriodLock::lockedUntil());
        $this->delete(route('stock-docs.destroy', $opening), ['reason' => 'test'])->assertSessionHas('popup');
        $this->assertSame(2, AuditLog::query()->where('action', 'period-lock.update')->count());
    }
}
