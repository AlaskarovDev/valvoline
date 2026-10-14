<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Services\FlowClassifier;
use App\Services\OfficialRevenueService;
use App\Services\ProfitLossService;
use App\Services\ReconciliationService;
use App\Support\Fmt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * «Balans / MZ / Cash Flow» faylı: oxuma, ödənişlərin təsnifatı və P&L-ə yalnız əməliyyat xərclərinin düşməsi.
 */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function workbook(int $year = 2025, float $salary = 1000.0, bool $export = false): string
    {
        $book = new Spreadsheet;
        $balance = $book->getActiveSheet()->setTitle('BALANS');
        $balance->fromArray([
            ['"VALVOLINE AZ" MMC'], [], [],
            ['Hesab №-si', 'Açıqlaması', ExcelDate::PHPToExcel(Carbon::create($year, 12, 31))],
            [41, 'Mallar', 900.0],
            [50, 'Kassa', 100.0],
            [62, 'Alıcılar və sifarişçilərlə hesablaşmalar', 50.0],
            [null, null, 1050.0],
            ['Hesab №-si', 'Açıqlaması', ExcelDate::PHPToExcel(Carbon::create($year, 12, 31))],
            [60, 'Malsatan və podratçılarla hesablaşmalar', 250.0],
            [76, 'Müxtəlif D və K hesablaşmalar (tesisçi borc müq)', 900.0],
            [80, 'Mənfəət və zərər', -100.0],
            [null, null, 1050.0],
        ]);

        $pl = $book->createSheet()->setTitle('MZ');
        $pl->fromArray([
            ['"VALVOLINE AZ" MMC'], [],
            ['Hesab №-si', 'Gəlir-Xərc maddələri', ExcelDate::PHPToExcel(Carbon::create($year, 12, 31))],
            [null, 'GƏLİRLƏR'],
            ['200.1.2', 'Topdansatışdan gəlir', 5000.0],
            ...($export ? [['217.2', 'AR hüdudlarından kənarda əldə edilmiş gəlirlər', 1200.0]] : []),
            [null, 'CƏMİ', 5000.0],
            [null, 'XƏRCLƏR'],
            ['221.1.2', 'Əmək Haqqı və ona bərabər tutulan ödəmələr', 4000.0],
            ['221.4', 'Malın dəyəri', 1100.0],
            ...($export ? [['232.1', 'AR hududlarından kənarda gəlirlərin əldə edilməsi ilə bağlı xərclər', 1000.0]] : []),
            [null, 'CƏMİ', 5100.0],
            [null, 'HESABAT DÖVRÜNDƏ MƏNFƏƏT (ZƏRƏR)', -100.0],
        ]);

        $jan = ExcelDate::PHPToExcel(Carbon::create($year, 1, 1));
        $feb = ExcelDate::PHPToExcel(Carbon::create($year, 2, 1));
        $flow = $book->createSheet()->setTitle('Cash Flow');
        $flow->fromArray([
            ['CASH FLOW - "VALVOLINE AZ" MMC', $jan, $feb, 'Total'],
            ['Dövrün əvvəlinə qalıq', 500.0, 300.0],
            ['GƏLİRLƏR'],
            ['Müştəri Bir MMC', 2000.0, 1000.0, 3000.0],
            ['Murad İsmayılov Məmməd', 100.0, null, 100.0],
            ['Təsisçidən', null, 5000.0, 5000.0],
            ...($export ? [['OOO VALSAR Rus', null, 600.0, 600.0]] : []),
            ['Total Gəlirlər:', 2100.0, 6000.0],
            ['ÖDƏNİŞLƏR'],
            ['ELLIS ENTERPRISES BV DORDRECHT NETERLANDS', 1500.0, 3000.0, 4500.0],
            ['Əmək Haqqı', $salary, $salary, 2 * $salary],
            ['DSMF', 220.0, 220.0, 440.0],
            ['Xanlarov Kamil Mirzexan (icare anbar)', 300.0, null, 300.0],
            ['MV', null, 700.0, 700.0],
            ['YGB', null, 400.0, 400.0],
            ['Murad İsmayılov Məmməd', null, 50.0, 50.0],
            ['Naməlum Firma MMC', 80.0, null, 80.0],
            ['Total Ödənişlər:', 3100.0, 4590.0],
            [],
            ['DİGƏR ƏMƏLİYYAT GƏLİRİ (XƏRCİ)'],
            ['Valyutanın yenidən qiymətləndirməsi, M (-Z)', -5.0, 2.0, -3.0],
            [],
            ['Dövrün sonuna Net Cash (Xalis Pul Vəsaitləri):', 300.0, 1712.0],
            [],
            ['Net Cash (Xalis Pul Vəsaitləri) açıqlaması'],
            ['Kassa AZN', 100.0, 100.0],
            ['RB Sebail AZN', 200.0, 1612.0],
        ]);

        $ledger = $book->createSheet()->setTitle('62');
        $ledger->fromArray([
            ['Щесаб цзря дювриййя галыг жядвяли: 62'], [], [], ['Субконто', 'Дюврцн сонуна галыг'], [null, 'Дебет', 'Кредит'],
            ['MILLA DAIRY', 50.0, ' '],
            ['       Mallarin yuklenmesi ve satisi 000082 (10.12.25)', 50.0, ' '],
            ['Йекун:', 50.0, ' '],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'acc').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function upload(string $path, string $name = '09_Balans_MZ_CashFlow.xlsx'): ImportBatch
    {
        $this->actingAs($this->admin)->post(route('import.upload'), ['file' => new UploadedFile($path, $name, null, null, true)])->assertRedirect();
        $batch = ImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame('accounting', $batch->file_type, 'fayl adından tip təyin olunur');
        $this->get(route('import.preview', $batch))->assertOk()->assertSee('Cash Flow');
        $this->post(route('import.confirm', $batch))->assertRedirect(route('import.index'));

        return $batch->refresh();
    }

    private function filters(?string $direction = 'VALVO'): ReportFilters
    {
        return new ReportFilters(Carbon::parse('2025-01-01'), Carbon::parse('2025-12-31')->endOfDay(), 'custom', direction: $direction);
    }

    public function test_workbook_is_parsed_and_balances(): void
    {
        $batch = $this->upload($this->workbook());
        $this->assertSame('done', $batch->status);

        $this->assertEqualsWithDelta(1050.0, DB::table('acc_balance_lines')->where('side', 'asset')->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(1050.0, DB::table('acc_balance_lines')->where('side', 'liability')->sum('amount'), 0.001);
        $this->assertSame('2025-12-31', (string) DB::table('acc_balance_lines')->value('as_of'));
        $this->assertEqualsWithDelta(-100.0, DB::table('acc_pl_lines')->where('section', 'result')->value('amount'), 0.001);
        $this->assertSame(2, DB::table('acc_pl_lines')->where('section', 'expense')->count(), 'CƏMİ sətirləri oxunmur');
        $this->assertEqualsWithDelta(8470.0, DB::table('bank_flow_lines')->where('section', 'payment')->sum('amount'), 0.001);
        $this->assertSame(0, DB::table('bank_flow_lines')->where('counterparty', 'like', 'Total%')->count());
        $this->assertSame(['2025-01', '2025-02'], DB::table('bank_flow_lines')->distinct()->orderBy('period')->pluck('period')->all());
        $this->assertSame(4, DB::table('bank_flow_balances')->count());
        $this->assertSame(1, DB::table('acc_ledger_lines')->whereNull('document')->count());
        $this->assertSame(1, DB::table('acc_ledger_lines')->whereNotNull('document')->count(), 'Йекун sətri oxunmur');
    }

    public function test_payments_are_classified_and_only_operating_expenses_reach_pnl(): void
    {
        $this->upload($this->workbook());
        $classifier = new FlowClassifier;
        $this->assertSame('cogs', $classifier->classify('ELLIS ENTERPRISES BV DORDRECHT NETERLANDS', 'payment')['type']);
        $this->assertSame('cogs', $classifier->classify('YGB', 'payment')['type']);
        $this->assertSame('tax', $classifier->classify('MV', 'payment')['type']);
        $this->assertSame('financing', $classifier->classify('Təsisçidən', 'income')['type']);
        $this->assertSame('revenue', $classifier->classify('Müştəri Bir MMC', 'income')['type']);
        $this->assertSame('İcarə', $classifier->classify('Xanlarov Kamil Mirzexan (icare anbar)', 'payment')['group']);
        $this->assertSame('İcarə', $classifier->classify('10TT023 VELIYEV FERHAD ZUBEYIR OGLU', 'payment')['group']);
        $this->assertSame('Cərimə və sanksiyalar', $classifier->classify('Cərimə (audit)', 'payment')['group']);
        $this->assertSame('uncertain', $classifier->classify('Naməlum Firma MMC', 'payment')['type']);
        $this->assertSame('uncertain', $classifier->classify('Murad İsmayılov Məmməd', 'payment')['type'], 'alıcı kimi də ödəyən şəxs');

        // P&L: Excel-in bütün ödənişləri 8470 − mal alışı / gömrük (ELLIS 4500 + YGB 400) = 3570
        $pnl = app(ProfitLossService::class);
        $this->assertEqualsWithDelta(3570.0, $pnl->bankExpenseTotal($this->filters()), 0.001);
        $this->assertEqualsWithDelta(3570.0, $pnl->bankExpenseTotal($this->filters('all')), 0.001);
        $this->assertEqualsWithDelta(0.0, $pnl->bankExpenseTotal($this->filters('TIR')), 0.001, 'TIR istiqamətinə bank xərci düşmür');
        $report = $pnl->build($this->filters(), 'month');
        $this->assertEqualsWithDelta(1600.0, $report['lines']['bank_expenses']['2025-01'], 0.001);
        $this->assertEqualsWithDelta(-3570.0, array_sum($report['lines']['net_profit']), 0.001);

        $this->get(route('pnl.index', ['from' => '2025-01-01', 'to' => '2025-12-31', 'gran' => 'year', 'resmi' => 'daxil']))
            ->assertOk()->assertSee('Rəsmi xərclər (bank)')->assertSee('Əmək Haqqı')->assertSee('Naməlum Firma MMC')->assertDontSee('ELLIS ENTERPRISES')->assertDontSee('Re-eksport nəticəsi');
    }

    public function test_manual_classification_changes_pnl_and_can_be_reset(): void
    {
        $this->upload($this->workbook());
        $pnl = app(ProfitLossService::class);
        $this->actingAs($this->admin)->post(route('accounting.classify'), [
            'normalized' => 'namelum firma mmc', 'counterparty' => 'Naməlum Firma MMC', 'type' => 'expense', 'expense_group' => 'Reklam və marketinq',
        ])->assertRedirect();
        // növ / qrup dəyişir, məbləğ P&L-də qalır — Excel-in bütün ödənişləri rəsmi xərcdir
        $this->assertEqualsWithDelta(3570.0, $pnl->bankExpenseTotal($this->filters()), 0.001);
        $this->assertSame('Reklam və marketinq', (new FlowClassifier)->classify('Naməlum Firma MMC', 'payment')['group']);
        $this->assertEqualsWithDelta(80.0, $pnl->bankExpensesByGroup($this->filters())['Reklam və marketinq']['2025-01'], 0.001);

        // yalnız «mal alışı / gömrük» seçiləndə P&L-dən çıxır (maya dəyərindədir)
        $this->post(route('accounting.classify'), ['normalized' => 'emek haqqi', 'counterparty' => 'Əmək Haqqı', 'type' => 'cogs'])->assertRedirect();
        $this->assertEqualsWithDelta(1570.0, $pnl->bankExpenseTotal($this->filters()), 0.001);

        $this->post(route('accounting.classify'), ['normalized' => 'emek haqqi', 'counterparty' => 'Əmək Haqqı', 'type' => 'reset'])->assertRedirect();
        $this->assertEqualsWithDelta(3570.0, $pnl->bankExpenseTotal($this->filters()), 0.001);
    }

    public function test_reimport_of_same_year_replaces_data(): void
    {
        $first = $this->upload($this->workbook(2025, 1000.0));
        $this->upload($this->workbook(2025, 1500.0));
        $this->assertSame(1, DB::table('bank_flow_lines')->where('counterparty', 'Əmək Haqqı')->where('period', '2025-01')->count());
        $this->assertEqualsWithDelta(3000.0, DB::table('bank_flow_lines')->where('counterparty', 'Əmək Haqqı')->sum('amount'), 0.001);
        $this->assertSame('replaced', $first->refresh()->status);

        $this->upload($this->workbook(2024), '09_Balans_2024.xlsx');
        $this->assertSame([2024, 2025], DB::table('acc_balance_lines')->distinct()->orderBy('year')->pluck('year')->map(fn ($y) => (int) $y)->all(), 'başqa il əvəzlənmir');
    }

    public function test_accounting_pages_render(): void
    {
        $this->actingAs($this->admin)->get(route('accounting.chart'))->assertOk()->assertSee('Hesablar planı')->assertSee('Mallar')->assertSee('205');
        $this->upload($this->workbook());
        $this->get(route('accounting.chart', ['section' => '2']))->assertOk()->assertSee('Qısamüddətli aktivlər')->assertSee('900,00');
        $this->get(route('accounting.balance'))->assertOk()->assertSee('Bərabərdir');
        $this->get(route('accounting.ledger', '62'), ['X-Modal' => '1'])->assertOk()->assertSee('MILLA DAIRY')->assertDontSee('<html', false);
        $this->get(route('accounting.pl'))->assertOk()->assertSee('Topdansatışdan gəlir');
        $this->get(route('accounting.cashflow'))->assertOk()->assertSee('Mal alışı / gömrük')->assertSee('ELLIS');
        $this->get(route('accounting.cashflow', ['section' => 'income']))->assertOk()->assertSee('Təsisçidən');
        $this->get(route('accounting.cashflow', ['view' => 'sheet']))->assertOk()
            ->assertSee('cf-sheet', false)->assertSee('Dövrün əvvəlinə qalıq')->assertSee('Xalis pul axını')->assertSee('ELLIS');

        $this->post(route('accounting.chart.store'), ['code' => '2051', 'name' => 'Mallar — anbar 2', 'nature' => 'A'])->assertRedirect();
        $this->assertSame('20', DB::table('chart_accounts')->where('code', '2051')->value('group_code'));

        $manager = User::factory()->create(['role' => 'menecer']);
        $this->actingAs($manager)->get(route('accounting.cashflow'))->assertForbidden();
    }

    public function test_chart_amount_source_and_years_overview(): void
    {
        $this->actingAs($this->admin);
        $this->upload($this->workbook(2024, 800.0), '09_Balans_2024.xlsx');
        $this->upload($this->workbook(2025));

        // tarix seçimi və məbləğə klik → mənbə popup-u (41 → 205)
        $this->get(route('accounting.chart', ['section' => '2', 'year' => 2024]))->assertOk()
            ->assertSee('31.12.2024')->assertSee(route('accounting.chart.source', ['code' => '205', 'year' => 2024]), false);
        $this->get(route('accounting.chart.source', ['code' => '205', 'year' => 2025]), ['X-Modal' => '1'])->assertOk()
            ->assertSee('Mallar')->assertSee('41 → 205')->assertSee('31.12.2024')->assertDontSee('<html', false);
        $this->get(route('accounting.chart.source', ['code' => '211', 'year' => 2025]))->assertOk()->assertSee('MILLA DAIRY');

        $this->get(route('accounting.overview'))->assertOk()
            ->assertSee('2024')->assertSee('2025')->assertSee('Aktivlər cəmi')->assertSee('Mal alışı / gömrük')->assertSee('Mallar');
    }

    public function test_dividends_taxes_and_foreign_companies_are_not_individual_expenses(): void
    {
        $c = new FlowClassifier;
        $this->assertSame('financing', $c->classify('Təsisçiyə DİVİDEND', 'payment')['type']);
        $this->assertSame('tax', $c->classify('Gəlir Vergisi', 'payment')['type']);
        $this->assertSame('refund', $c->classify('OOO VALSAR Rus (qaytarma)', 'payment')['type']);
        $this->assertSame('uncertain', $c->classify('OOO ORLAN, RUSIYA', 'payment')['type']);
        $this->assertSame('Nəqliyyat və logistika', $c->classify('TIBER LOGISTIK', 'payment')['group']);
        $this->assertSame('Reklam və marketinq', $c->classify('PROMOLINE ADVERTISING MMC', 'payment')['group']);
        $this->assertSame('Bank xidməti', $c->classify('Konvertasiya komisyonu', 'payment')['group']);
    }

    public function test_reexport_from_mz_is_added_to_pnl_by_foreign_buyer_receipts(): void
    {
        $this->upload($this->workbook(export: true));
        $pnl = app(ProfitLossService::class);
        $report = $pnl->build($this->filters(), 'month');

        // MZ 217.2 = 1200, 232.1 = 1000 — hamısı OOO VALSAR-ın ödədiyi fevrala düşür
        $this->assertEqualsWithDelta(1200.0, $report['lines']['export_revenue']['2025-02'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $report['lines']['export_cost']['2025-02'], 0.001);
        $this->assertEqualsWithDelta(0.0, $report['lines']['export_revenue']['2025-01'], 0.001);
        $this->assertEqualsWithDelta(-3570.0 + 200.0, array_sum($report['lines']['net_profit']), 0.001);
        $this->assertEqualsWithDelta(0.0, $pnl->exportResultTotal($this->filters('TIR')), 0.001);

        $this->get(route('pnl.index', ['from' => '2025-01-01', 'to' => '2025-12-31', 'gran' => 'year']))
            ->assertOk()->assertSee('Re-eksport nəticəsi')->assertSee('AR hüdudlarından kənar satış');
    }

    public function test_expenses_page_adds_official_bank_expenses_with_source_and_detail(): void
    {
        $this->upload($this->workbook());
        $range = ['from' => '2025-01-01', 'to' => '2025-12-31'];

        $this->get(route('expenses.index', $range))->assertOk()
            ->assertSee('Rəsmi')->assertSee('Sosial sığorta (DSMF)')->assertSee(Fmt::money(3570), false);
        $this->get(route('expenses.index', $range + ['src' => 'cash']))->assertOk()->assertDontSee('Sosial sığorta (DSMF)');

        $this->get(route('expenses.detail', $range + ['src' => 'bank', 'name' => 'Əmək haqqı', 'col' => '2025-01']), ['X-Modal' => '1'])
            ->assertOk()->assertSee('Əmək Haqqı')->assertSee(Fmt::money(1000), false)->assertDontSee('DSMF')->assertDontSee('<html', false);
        $this->get(route('expenses.detail', $range + ['src' => 'bank', 'name' => 'İcarə', 'col' => 'total']))
            ->assertOk()->assertSee('Xanlarov Kamil')->assertSee('<html', false);
    }

    public function test_reconciliation_page_compares_system_with_accounting_file(): void
    {
        $this->upload($this->workbook(export: true));

        $this->get(route('accounting.reconcile'))->assertOk()
            ->assertSee('Re-eksport (AR hüdudlarından kənar)')->assertSee(Fmt::money(1200), false)
            ->assertSee('Sistemdə yox idi')->assertSee('Sosial sığorta (DSMF)')
            ->assertSee('Müştəri Bir MMC')->assertSee('Yoxlanılmalı')->assertSee('Dövrün əvvəlinə qalıqlar');

        $rows = app(ReconciliationService::class)->build(2025);
        $dsmf = collect($rows['expenses'])->firstWhere('group', 'Sosial sığorta (DSMF)');
        $this->assertSame(ReconciliationService::STATUS_ADDED, $dsmf->status);
        $this->assertEqualsWithDelta(440.0, $dsmf->bank, 0.001);
        $this->assertEqualsWithDelta(4000.0, collect($rows['expenses'])->firstWhere('group', 'Əmək haqqı')->mz, 0.001);
        $this->assertEqualsWithDelta(1100.0, collect($rows['purchases'])->get(2)->amount, 0.001);
    }

    public function test_official_receipts_reach_pnl_only_when_marked_as_missing_sales(): void
    {
        $this->upload($this->workbook());
        $pnl = app(ProfitLossService::class);
        $official = app(OfficialRevenueService::class);

        // ödəyici sistemdə tapılmır → yoxlanılmalı, P&L-ə düşmür (satış gəliri sistemdə satış faylından gəlir)
        $payer = $official->payers(2025)->firstWhere('counterparty', 'Müştəri Bir MMC');
        $this->assertSame(OfficialRevenueService::PENDING, $payer->decision);
        $this->assertEqualsWithDelta(3000.0, $payer->total, 0.001);
        $this->assertNull($official->payers(2025)->firstWhere('counterparty', 'Təsisçidən'), 'təsisçi borcu gəlir deyil');
        $this->assertEqualsWithDelta(-3570.0, array_sum($pnl->build($this->filters())['lines']['net_profit']), 0.001);

        $this->post(route('accounting.revenue.decide'), ['normalized' => $payer->normalized, 'counterparty' => $payer->counterparty, 'decision' => 'extra'])->assertRedirect();
        $report = $pnl->build($this->filters(), 'month');
        $this->assertEqualsWithDelta(2000.0, $report['lines']['official_extra']['2025-01'], 0.001);
        $this->assertEqualsWithDelta(-3570.0 + 3000.0, array_sum($report['lines']['net_profit']), 0.001);
        $this->assertEqualsWithDelta(0.0, array_sum($pnl->build($this->filters('TIR'))['lines']['official_extra'] ?? []), 0.001);

        $summary = $official->cashSummary($this->filters());
        $this->assertEqualsWithDelta(500.0, $summary['opening'], 0.001, 'açılış — yanvarın əvvəlinə qalıq');
        $this->assertEqualsWithDelta(1712.0, $summary['closing'], 0.001);
        $this->assertEqualsWithDelta(3000.0, $summary['extra'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $summary['founder'], 0.001);

        $this->get(route('pnl.index', ['from' => '2025-01-01', 'to' => '2025-12-31', 'gran' => 'year']))->assertOk()
            ->assertSee('Rəsmi gəlir — sistemdə olmayan satış (bank)')->assertSee('Rəsmi pul axını (mühasibat Cash Flow)')->assertSee('Dövrün əvvəlinə qalıq');
        $this->get(route('dashboard', ['from' => '2025-01-01', 'to' => '2025-12-31']))->assertOk()
            ->assertSee('Rəsmi göstəricilər (mühasibat)')->assertSee(Fmt::money(3000.0), false, 'idarə paneli P&L kimi — rəsmi xərclər standart xaric');

        $this->post(route('accounting.revenue.decide'), ['normalized' => $payer->normalized, 'counterparty' => $payer->counterparty, 'decision' => 'auto'])->assertRedirect();
        $this->assertSame(0, DB::table('revenue_decisions')->count());
        $this->post(route('accounting.revenue.decide'), ['normalized' => 'x', 'counterparty' => 'x', 'decision' => 'bogus'])->assertSessionHasErrors('decision');
    }

    public function test_pnl_sheet_view_with_manual_openings_and_official_toggle(): void
    {
        $this->upload($this->workbook());
        $range = ['from' => '2025-01-01', 'to' => '2025-12-31'];

        // yanvar: yalnız rəsmi xərclər 1600 → xalis −1600; əl ilə açılış 1000 → son qalıq −600, fevral oradan davam edir
        $this->post(route('pnl.openings'), ['opening' => ['2025-01' => '1000', 'bogus' => '5']])->assertRedirect();
        $this->assertSame(['2025-01'], DB::table('pnl_openings')->pluck('period')->all());
        $this->get(route('flowsheet.index', $range + ['resmi' => 'daxil']))->assertOk()->assertSee('Cash Flow (idarəetmə)')
            ->assertSee('Mənfəət-zərər — Excel görünüşü')->assertSee('Dövrün əvvəlinə qalıq')->assertSee('Naməlum Firma MMC')
            ->assertSee('value="1000"', false)->assertSee('placeholder="-600.00"', false)->assertSee(Fmt::money(-600, 2, false), false);

        $this->post(route('pnl.openings'), ['opening' => ['2025-01' => '']])->assertRedirect();
        $this->assertSame(0, DB::table('pnl_openings')->count(), 'boş xana əl ilə qalığı silir');

        // standart «Rəsmi xərclər: Xaric» — bank xərcləri P&L-ə yalnız «Daxil» seçiləndə düşür
        $pnl = app(ProfitLossService::class);
        $this->assertEqualsWithDelta(0.0, array_sum($pnl->build($this->filters(), 'month', false)['lines']['bank_expenses'] ?? []), 0.001);
        $this->assertEqualsWithDelta(0.0, array_sum($pnl->build($this->filters(), 'month', false)['lines']['net_profit'] ?? []), 0.001);
        $this->get(route('pnl.index', $range + ['gran' => 'year']))->assertOk()->assertDontSee('− Rəsmi xərclər (bank)');
        $this->get(route('pnl.index', $range + ['resmi' => 'daxil', 'gran' => 'year']))->assertOk()->assertSee('− Rəsmi xərclər (bank)');
        $this->get(route('pnl.detail', $range + ['line' => 'net_profit', 'col' => 'total']), ['X-Modal' => '1'])
            ->assertOk()->assertSee(Fmt::money(0), false);
        $this->get(route('pnl.detail', $range + ['resmi' => 'daxil', 'line' => 'net_profit', 'col' => 'total']), ['X-Modal' => '1'])
            ->assertOk()->assertSee(Fmt::money(-3570), false);
    }

    public function test_xls_upload_is_only_allowed_for_the_accounting_file(): void
    {
        $ole = "\xD0\xCF\x11\xE0".str_repeat("\0", 60);
        $this->actingAs($this->admin)
            ->post(route('import.upload'), ['file' => UploadedFile::fake()->createWithContent('04_Satislar.xls', $ole)])
            ->assertSessionHasErrors('file');
    }
}
