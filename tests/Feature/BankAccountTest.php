<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BankStatementService;
use App\Support\Fmt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        foreach ([['2025-11', 'Turan Bank AZN', 900.0], ['2025-12', 'Turan Bank AZN', 1150.5], ['2025-12', 'RB Sebail EURO', 12.0], ['2025-12', 'Kassa AZN', 300.0]] as [$period, $account, $amount]) {
            DB::table('bank_flow_balances')->insert(['year' => 2025, 'period' => $period, 'account' => $account, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Bank çıxarışı: başlıqdan əvvəl bank məlumatı, mətn və Excel tarixləri, «1 234,50» formatlı məbləğlər.
     */
    private function statement(): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['TURAN BANK ASC — Hesabdan çıxarış'],
            ['Hesab: AZ00TURA00000000000000000001', null, null, null, null, null],
            [],
            ['Tarix', 'Sənəd №', 'Kontragent', 'Təyinat', 'Debet', 'Kredit', 'Qalıq'],
            ['05.12.2025', '101', 'Müştəri Bir MMC', 'Mal haqqı, faktura 12', null, '1 234,50', '2 134,50'],
            [ExcelDate::PHPToExcel(Carbon::create(2025, 12, 10)), '102', 'DSMF', 'Sosial sığorta haqqı', '220,00', null, '1 914,50'],
            ['12.12.2025', '103', 'Xanlarov Kamil Mirzexan (icare anbar)', 'Anbar icarəsi dekabr', '764', null, '1 150,50'],
            [null, null, null, 'Cəmi dövriyyə', '984,00', '1 234,50', null],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'st').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function test_accounts_are_created_from_accounting_balances(): void
    {
        $this->actingAs($this->admin)->get(route('bank.index'))->assertOk()
            ->assertSee('Turan Bank AZN')->assertSee('RB Sebail EURO')->assertSee('Rabitəbank')->assertSee(Fmt::money(1462.5), false);

        $this->assertSame(['currency' => 'EUR', 'kind' => 'bank', 'bank' => 'Rabitəbank'], (array) DB::table('bank_accounts')->where('name', 'RB Sebail EURO')->first(['currency', 'kind', 'bank']));
        $this->assertSame('cash', DB::table('bank_accounts')->where('name', 'Kassa AZN')->value('kind'));
        $this->assertSame(['bank' => 'Turan Bank', 'currency' => 'AZN', 'kind' => 'card'], BankStatementService::describe('Turan Bank AZN Kart'));

        // ikinci açılış hesabları təkrarlamır
        $this->get(route('bank.index'))->assertOk();
        $this->assertSame(3, DB::table('bank_accounts')->count());
    }

    public function test_statement_import_parses_dates_amounts_and_skips_duplicates(): void
    {
        $this->actingAs($this->admin)->get(route('bank.index'));
        $account = (int) DB::table('bank_accounts')->where('name', 'Turan Bank AZN')->value('id');

        $this->post(route('bank.import', $account), ['file' => new UploadedFile($this->statement(), 'cixaris.xlsx', null, null, true)])
            ->assertRedirect(route('bank.show', ['account' => $account, 'year' => '2025']));
        $this->assertSame(3, DB::table('bank_transactions')->where('bank_account_id', $account)->count(), 'cəm sətri əməliyyat deyil');
        $this->assertEqualsWithDelta(1234.5, DB::table('bank_transactions')->sum('amount_in'), 0.001);
        $this->assertEqualsWithDelta(984.0, DB::table('bank_transactions')->sum('amount_out'), 0.001);
        $this->assertSame('2025-12-10', (string) DB::table('bank_transactions')->where('doc_no', '102')->value('date'));

        $this->post(route('bank.import', $account), ['file' => new UploadedFile($this->statement(), 'cixaris.xlsx', null, null, true)])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '0 yeni') && str_contains($s, '3 təkrar'));
        $this->assertSame(3, DB::table('bank_transactions')->count());

        // ay sonu: çıxarış qalığı 1150,50 = mühasibat faylı → ✓; növlər Cash Flow təsnifatı ilə
        $this->get(route('bank.show', ['account' => $account, 'year' => 2025]))->assertOk()
            ->assertSee('Müştəri Bir MMC')->assertSee('Satış gəliri')->assertSee('Əməliyyat xərci')->assertSee('✓');
        $this->get(route('bank.show', ['account' => $account, 'year' => 2025, 'dir' => 'out', 'q' => 'icare']))->assertOk()
            ->assertSee('Xanlarov')->assertDontSee('Müştəri Bir MMC');
    }

    public function test_statement_without_recognised_header_is_rejected(): void
    {
        $this->actingAs($this->admin)->get(route('bank.index'));
        $account = (int) DB::table('bank_accounts')->value('id');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Ad', 'Soyad'], ['Əli', 'Əliyev']]);
        $path = tempnam(sys_get_temp_dir(), 'st').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->post(route('bank.import', $account), ['file' => new UploadedFile($path, 'x.xlsx', null, null, true)])->assertSessionHasErrors('file');
        $this->assertSame(0, DB::table('bank_transactions')->count());
    }

    public function test_manual_transaction_and_account_management(): void
    {
        $this->actingAs($this->admin)->post(route('bank.store'), ['name' => 'PAŞA Bank USD', 'bank' => 'PAŞA Bank', 'currency' => 'USD', 'kind' => 'bank'])->assertRedirect();
        $account = (int) DB::table('bank_accounts')->where('name', 'PAŞA Bank USD')->value('id');

        $this->post(route('bank.tx.store', $account), ['date' => '2026-03-04', 'direction' => 'out', 'counterparty' => 'Azercell Telecom', 'amount' => '45.5'])
            ->assertRedirect(route('bank.show', ['account' => $account, 'year' => '2026']));
        $tx = DB::table('bank_transactions')->first();
        $this->assertSame('2026-03', $tx->period);
        $this->assertEqualsWithDelta(45.5, (float) $tx->amount_out, 0.001);
        $this->assertSame('manual', $tx->source);
        $this->get(route('bank.show', ['account' => $account, 'year' => 2026]))->assertOk()->assertSee('Azercell Telecom')->assertSee('Əməliyyat xərci');

        $this->put(route('bank.update', $account), ['name' => 'PAŞA Bank USD', 'currency' => 'USD', 'kind' => 'bank', 'iban' => 'AZ12PAHA', 'is_active' => '0'])->assertRedirect();
        $this->assertSame(0, (int) DB::table('bank_accounts')->where('id', $account)->value('is_active'));

        $this->delete(route('bank.tx.destroy', $tx->id))->assertRedirect();
        $this->assertSame(0, DB::table('bank_transactions')->count());

        $this->post(route('bank.tx.store', $account), ['date' => '2026-03-04', 'direction' => 'out', 'counterparty' => 'X', 'amount' => '-5'])->assertSessionHasErrors('amount');
    }

    public function test_statement_number_and_date_formats(): void
    {
        $this->assertSame(1234.5, BankStatementService::number('1 234,50'));
        $this->assertSame(1234.5, BankStatementService::number('1,234.50'));
        $this->assertSame(1234.5, BankStatementService::number('1.234,50'));
        $this->assertSame(-80.0, BankStatementService::number('-80'));
        $this->assertSame('2025-12-05', BankStatementService::date('05.12.2025'));
        $this->assertSame('2025-12-05', BankStatementService::date('05.12.2025 14:30'));
        $this->assertSame('2025-12-05', BankStatementService::date('2025-12-05'));
        $this->assertNull(BankStatementService::date('Cəmi'));
    }

    public function test_bank_pages_are_admin_only(): void
    {
        $manager = User::factory()->create(['role' => 'menecer']);
        $this->actingAs($manager)->get(route('bank.index'))->assertForbidden();
    }
}
