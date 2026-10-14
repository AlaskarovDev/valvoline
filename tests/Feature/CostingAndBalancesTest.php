<?php

namespace Tests\Feature;

use App\Imports\ImporterRegistry;
use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Models\ExpenseCategory;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\SalesLine;
use App\Models\SalesOrder;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Services\ChartLedgerService;
use App\Services\CostingService;
use App\Services\ProfitLossService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWorkbooks;
use Tests\TestCase;

/**
 * Əl ilə hesablanmış kiçik ssenari: hərəkətli orta maya, eyni gün sırası, qrup / ilk alış ehtiyat mayası,
 * qaytarma, hədiyyə / daxili istifadə, transfer, sayım, müştəri balansı, FIFO borc yaşı, P&L.
 */
class CostingAndBalancesTest extends TestCase
{
    use BuildsWorkbooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2024-04-15 12:00:00');
        // bu ssenarinin rəqəmləri hərəkətli orta üzrədir; FIFO ayrıca testdə
        config(['app.costing_method' => 'average']);

        $this->importFile('products', $this->productsFile([
            ['100001', 'Yağ A 4L', 'GRP1', 'Mühərrik yağı', 4, 4, 50],
            ['100002', 'Yağ B 4L NSW', 'GRP1', 'Mühərrik yağı', 4, 4, 50, 'OLD-B'],
            ['VE11258', 'Antifriz C 1L', 'GRP2', 'Antifriz', 1, 12, 10],
        ]));
        $this->importFile('customers', $this->customersFile([
            ['885852', 'Müştəri Bir', 'Menecer A'],
            ['885853', 'Müştəri İki MMC', 'Menecer B'],
            ['900001', 'Ofis', 'Menecer A', 'Daxili (müştəri deyil)'],
        ], [['MUSTERI IKI MMC', '885853']]));
        $this->importFile('purchases', $this->purchasesFile([
            [0, '13.02.2024', '100001', 10, 5.00, 'TƏXMİNİ maya'],
            [1, '01.03.2024', '100001', 10, 8.00],
            [2, '01.04.2024', 'VE11258', 5, 3.00],
            [3, '01.05.2024', 'OLD-B', 2, 10.00],
        ]));
        $this->importFile('sales', $this->salesFile([
            ['20.02.2024', 'S1', 'Menecer A', '885852', 'Müştəri Bir', '100001', 4, 50, 0, 200],
            ['01.03.2024', 'S2', 'Menecer A', '885852', 'Müştəri Bir', '100001', 5, 50, 0.1, 225],
            ['03.03.2024', 'S3', 'Menecer B', '885853', 'Müştəri İki MMC', '100001', 1, 50, 0, 0, 'Hədiyyə / pulsuz'],
            ['05.03.2024', 'S4', 'Menecer B', null, 'MUSTERI IKI MMC', '100002', 2, 50, 0, 100],
            ['10.03.2024', 'S5', 'Menecer B', '885853', 'Müştəri İki MMC', 'VE11258', 1, 10, 0, 10],
            ['12.03.2024', 'S6', 'Menecer A', '885852', 'Müştəri Bir', 'ZZZ-404', 1, 10, 0, 10],
            ['15.03.2024', 'S7', 'Menecer A', '900001', 'Ofis', '100001', 1, 50, 0, 0, 'Daxili istifadə'],
        ]));
        $this->importFile('returns', $this->returnsFile([
            ['02.03.2024', 'R1', '885852', '100001', 1, 45, 45, 'Müştəri qaytarması', 'Zədəli'],
        ]));
        $this->importFile('payments', $this->paymentsFile([
            ['10.03.2024', '885852', 100, 0],
            ['20.04.2024', '885852', 0, 200],
            ['01.05.2024', '885853', -20, 0, 'Geri ödəniş'],
        ]));
        $this->importFile('stock', $this->stockFile(
            [['01.06.2024', '100001', -2]],
            [['01.04.2024', 'TR1', 'Çıxış', 'Əsas anbar', 'Cənub bölgəsi — Anbar 2', '100001', 3]],
        ));
        $this->importFile('cash', $this->cashFile([
            ['05.03.2024', 'Əmək haqqı', 0, 100, 'Xərc', 'Bəli', 'VALVO'],
            ['06.03.2024', 'TIR xərcləri', 0, 40, 'Xərc', 'Bəli', 'TIR'],
            ['07.03.2024', 'Satış pulunun kassaya təhvili', 300, 0, 'Mədaxil', 'Xeyr', 'VALVO'],
            ['08.03.2024', 'Depozit faizi', 15, 0, 'Mədaxil', 'Bəli', 'VALVO'],
            ['09.03.2024', 'İdxal mal ödənişi', 0, 120, 'Xərc', 'Xeyr', 'VALVO'],
            ['10.03.2024', 'Ferhad (aylıq ödəniş)', 0, 50, 'Xərc', 'Bəli', 'VALVO'],
        ], [
            ['Əmək haqqı', 'Xərc', 'Bəli'], ['TIR xərcləri', 'Xərc', 'Bəli'], ['Satış pulunun kassaya təhvili', 'Mədaxil', 'Xeyr'],
            ['Depozit faizi', 'Mədaxil', 'Bəli'], ['İdxal mal ödənişi', 'Xərc', 'Xeyr'], ['Ferhad (aylıq ödəniş)', 'Xərc', 'Bəli'],
        ], [['08.03.2024', 215]]));

        $this->rebuild();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function line(string $orderNo): SalesLine
    {
        return SalesLine::query()->where('order_no', $orderNo)->firstOrFail();
    }

    public function test_codes_are_stored_as_text_and_old_codes_resolve(): void
    {
        $this->assertSame('VE11258', Product::query()->where('code', 'VE11258')->value('code'));
        $this->assertSame('885852', Customer::query()->where('name', 'Müştəri Bir')->value('code'));
        // köhnə kod yazılışı (OLD-B) ilə gələn alış 100002-yə bağlanır
        $this->assertSame(1, DB::table('purchase_lines')->where('product_code', 'OLD-B')->where('product_id', Product::query()->where('code', '100002')->value('id'))->count());
    }

    public function test_moving_weighted_average_with_same_day_purchase_before_sale(): void
    {
        $this->assertEqualsWithDelta(5.0, (float) $this->line('S1')->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $this->line('S1')->cogs, 0.0001);
        // 01.03: alış eyni gündə satışdan əvvəl: (6×5 + 10×8) / 16 = 6,875
        $this->assertEqualsWithDelta(6.875, (float) $this->line('S2')->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(34.375, (float) $this->line('S2')->cogs, 0.0001);
        $this->assertEqualsWithDelta(190.625, (float) $this->line('S2')->gross_profit, 0.0001);
        // hədiyyə və daxili istifadə: gəlir 0, maya xərcdir
        $this->assertEqualsWithDelta(6.875, (float) $this->line('S3')->cogs, 0.0001);
        $this->assertEqualsWithDelta(-6.875, (float) $this->line('S3')->gross_profit, 0.0001);
        $this->assertEqualsWithDelta(6.875, (float) $this->line('S7')->cogs, 0.0001);
    }

    public function test_fifo_issues_oldest_layers_first(): void
    {
        config(['app.costing_method' => 'fifo']);
        $this->rebuild();

        // 10@5 açılış → S1 4 ədəd: 5
        $this->assertEqualsWithDelta(5.0, (float) $this->line('S1')->unit_cost, 0.0001);
        // 01.03: 10@8 alış, amma S2 (5 ədəd) əvvəlcə qalan 6@5 partiyasından çıxır
        $this->assertEqualsWithDelta(5.0, (float) $this->line('S2')->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $this->line('S2')->cogs, 0.0001);
        // R1 qaytarması sonuncu çıxış mayası (5) ilə girir: 1@5, 10@8, 1@5 → S3 hədiyyə 1@5, S7 daxili 1@8
        $this->assertEqualsWithDelta(5.0, (float) $this->line('S3')->cogs, 0.0001);
        $this->assertEqualsWithDelta(8.0, (float) $this->line('S7')->cogs, 0.0001);
        // 01.06 sayım −2 → 2@8 çıxır; qalan 7@8 + 1@5 → orta 7,625; anbar dəyəri = 61
        $a = Product::query()->where('code', '100001')->first();
        $this->assertEqualsWithDelta(8.0, (float) $a->stock_qty, 0.0001);
        $this->assertEqualsWithDelta(7.625, (float) $a->current_avg_cost, 0.0001);
        $this->assertEqualsWithDelta(16.0, (float) DB::table('stock_movements')->where('type', 'adjustment')->value('total_cost') * -1, 0.0001);
        // mayası olmayan B: qrup / ilk alış ehtiyat mayası əvvəlki kimi
        $this->assertTrue($this->line('S4')->cost_fallback);
        $this->assertSame('fifo', CostingService::method());
    }

    public function test_cost_fallbacks_use_group_average_then_first_purchase(): void
    {
        // B-nin mayası yoxdur → eyni qrupun (GRP1) son orta mayası 6,875
        $this->assertEqualsWithDelta(6.875, (float) $this->line('S4')->unit_cost, 0.0001);
        $this->assertTrue($this->line('S4')->cost_fallback);
        // C: qrupda maya yoxdur → malın ilk alış mayası (sonrakı tarixdə) 3,00
        $this->assertEqualsWithDelta(3.0, (float) $this->line('S5')->unit_cost, 0.0001);
        // B sonradan mənfi qalıqla alınır: köhnə qalıq 0 götürülür → yeni orta = alış mayası
        $this->assertEqualsWithDelta(10.0, (float) Product::query()->where('code', '100002')->value('current_avg_cost'), 0.0001);
    }

    public function test_unknown_product_is_kept_as_pending_and_name_alias_resolves_customer(): void
    {
        $pending = $this->line('S6');
        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->cogs);
        $this->assertSame(Customer::query()->where('code', '885853')->value('id'), $this->line('S4')->customer_id);
    }

    public function test_net_sales_cogs_and_gross_profit(): void
    {
        $filters = new ReportFilters(Carbon::parse('2024-01-01'), Carbon::parse('2024-12-31')->endOfDay(), 'custom');
        $totals = SalesSummaryQuery::totals($filters);
        $this->assertEqualsWithDelta(545.0, $totals->sales, 0.001);
        $this->assertEqualsWithDelta(45.0, $totals->returns, 0.001);
        $this->assertEqualsWithDelta(500.0, $totals->net_sales, 0.001);
        // COGS = 20 + 34,375 + 6,875 + 13,75 + 3 + 6,875 − 6,875 (qaytarma) = 78
        $this->assertEqualsWithDelta(78.0, $totals->cogs, 0.001);
        $this->assertEqualsWithDelta(422.0, $totals->gross_profit, 0.001);
        $this->assertEqualsWithDelta(0.844, $totals->margin, 0.0001);
    }

    public function test_raw_day_range_matches_monthly_aggregate(): void
    {
        $monthly = SalesSummaryQuery::totals(new ReportFilters(Carbon::parse('2024-03-01'), Carbon::parse('2024-03-31')->endOfDay(), 'custom'));
        $days = SalesSummaryQuery::totals(new ReportFilters(Carbon::parse('2024-03-01'), Carbon::parse('2024-03-11')->endOfDay(), 'custom'));
        $this->assertEqualsWithDelta(345.0, $monthly->sales, 0.001);   // S2 225 + S4 100 + S5 10 + S6 10
        $this->assertEqualsWithDelta(335.0, $days->sales, 0.001);      // S6 (12.03) xaricdir
    }

    public function test_order_profit_after_returns(): void
    {
        $order = SalesOrder::query()->where('order_no', 'S2')->firstOrFail();
        $this->assertEqualsWithDelta(225.0, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(45.0, (float) $order->return_amount, 0.001);
        $this->assertEqualsWithDelta(152.5, (float) $order->net_profit, 0.001);
    }

    public function test_stock_balances_transfers_and_adjustments(): void
    {
        $a = Product::query()->where('code', '100001')->firstOrFail();
        $this->assertEqualsWithDelta(8.0, (float) $a->stock_qty, 0.0001);
        $this->assertEqualsWithDelta(6.875, (float) $a->current_avg_cost, 0.0001);
        $this->assertTrue($a->cost_estimated);

        $byWarehouse = DB::table('stock_balances as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.product_id', $a->id)->pluck('s.qty', 'w.code')->map(fn ($v) => (float) $v)->all();
        $this->assertEquals(['MAIN' => 5.0, 'A2' => 3.0], $byWarehouse);

        $filters = new ReportFilters(Carbon::parse('2000-01-01'), Carbon::parse('2030-01-01'), 'all');
        $rows = app(StockService::class)->balances($filters, Carbon::parse('2026-10-01'));
        $this->assertEqualsWithDelta(12.0, $rows->sum('qty'), 0.0001);
        $this->assertEqualsWithDelta(67.0, $rows->sum('value'), 0.0001); // 8 × 6,875 + 4 × 3

        // tarixə qalıq: 01.03.2024 sonunda A = 11
        $march = app(StockService::class)->balances($filters, Carbon::parse('2024-03-01')->endOfDay());
        $this->assertEqualsWithDelta(11.0, $march->firstWhere('code', '100001')->qty, 0.0001);
    }

    public function test_customer_balance_and_fifo_aging(): void
    {
        $one = CustomerBalance::query()->whereHas('customer', fn ($q) => $q->where('code', '885852'))->firstOrFail();
        // 200 + 225 + 10 − 45 − 300 = 90
        $this->assertEqualsWithDelta(90.0, (float) $one->balance, 0.001);
        // as_of = son əməliyyat 01.05.2024: S2-dən qalan 80 (61 gün), S6 10 (50 gün)
        $this->assertSame('2024-05-01', $one->as_of->toDateString());
        $this->assertEqualsWithDelta(80.0, (float) $one->aging_61_90, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $one->aging_31_60, 0.001);

        // 0–30 gün qrupuna düşmək üçün minimal ödəniş = 31+ gün qruplarının cəmi (FIFO ən köhnəni bağlayır)
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->actingAs($admin)->get(route('debts.index', ['debts_q' => 'Müştəri Bir']));
        $page->assertOk()->assertSee('0–30-a düşmək üçün min. ödəniş');
        $minPayment = (float) $page->viewData('rows')->first()->min_payment;
        $this->assertEqualsWithDelta(90.0, $minPayment, 0.001);

        $two = CustomerBalance::query()->whereHas('customer', fn ($q) => $q->where('code', '885853'))->firstOrFail();
        // 100 + 10 (hədiyyə gəlir deyil) − (−20 geri ödəniş) = 130
        $this->assertEqualsWithDelta(130.0, (float) $two->balance, 0.001);
    }

    public function test_profit_and_loss_respects_direction_and_profit_flag(): void
    {
        $filters = new ReportFilters(Carbon::parse('2024-01-01'), Carbon::parse('2024-12-31')->endOfDay(), 'this_year', direction: 'VALVO');
        $report = app(ProfitLossService::class)->build($filters, 'year');
        $this->assertEqualsWithDelta(150.0, $report['lines']['expenses']['2024'], 0.001);
        $this->assertEqualsWithDelta(15.0, $report['lines']['other_income']['2024'], 0.001);
        $this->assertEqualsWithDelta(287.0, $report['lines']['net_profit']['2024'], 0.001);
        $this->assertEqualsWithDelta(13.75, $report['lines']['gift_cogs']['2024'], 0.001);

        $all = clone $filters;
        $all->direction = 'all';
        $this->assertEqualsWithDelta(247.0, app(ProfitLossService::class)->build($all, 'year')['lines']['net_profit']['2024'], 0.001);

        // UI-dan bayraq dəyişəndə hesabat dərhal dəyişir
        $admin = User::factory()->create(['role' => 'admin']);
        $category = ExpenseCategory::query()->where('name', 'Əmək haqqı')->firstOrFail();
        $this->actingAs($admin)->put(route('categories.update', $category), ['affects_profit' => 0, 'op_type' => 'Xərc'])->assertRedirect();
        $this->assertEqualsWithDelta(387.0, app(ProfitLossService::class)->build($filters, 'year')['lines']['net_profit']['2024'], 0.001);

        // təkrar import istifadəçinin bayrağını ezmir
        $this->importFile('cash', $this->fixtureDir().DIRECTORY_SEPARATOR.'08_Xercler.xlsx');
        $this->assertFalse(ExpenseCategory::query()->where('name', 'Əmək haqqı')->value('affects_profit'));
        $this->assertEqualsWithDelta(387.0, app(ProfitLossService::class)->build($filters, 'year')['lines']['net_profit']['2024'], 0.001);
    }

    public function test_founder_payments_are_not_expenses_but_shown_below_net_profit(): void
    {
        $filters = new ReportFilters(Carbon::parse('2024-01-01'), Carbon::parse('2024-12-31')->endOfDay(), 'this_year', direction: 'VALVO');
        $this->artisan('category:set', ['name' => 'Ferhad', '--type' => 'Təsisçiyə ödəniş', '--profit' => 0])->assertSuccessful();

        $report = app(ProfitLossService::class)->build($filters, 'year');
        $this->assertEqualsWithDelta(100.0, $report['lines']['expenses']['2024'], 0.001, 'təsisçi ödənişi xərcdən çıxdı');
        $this->assertEqualsWithDelta(337.0, $report['lines']['net_profit']['2024'], 0.001);
        $this->assertEqualsWithDelta(50.0, $report['lines']['founder']['2024'], 0.001);
        $this->assertEqualsWithDelta(287.0, $report['lines']['retained']['2024'], 0.001, 'bölüşdürülməmiş mənfəət');
        $this->assertArrayHasKey('Ferhad (aylıq ödəniş)', $report['founder']);

        // idarəetmə balansı: keçmiş illərin təsisçiyə ödənişləri 343-də deyil, 344 «Elan edilmiş dividendlər»də
        $balance = app(ChartLedgerService::class)->managementBalance(Carbon::parse('2025-06-30'));
        $this->assertEqualsWithDelta(337.0, array_sum(array_column($balance['343'], 'amount')), 0.001, '343 — yalnız 2024-ün xalis mənfəəti');
        $this->assertEqualsWithDelta(50.0, array_sum(array_column($balance['344'], 'amount')), 0.001);
        $this->assertStringContainsString('2024', $balance['344'][0]['label']);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('pnl.index', ['from' => '2024-01-01', 'to' => '2024-12-31', 'direction' => 'VALVO']))
            ->assertOk()->assertSee('Təsisçiyə ödənişlər')->assertSee('Bölüşdürülməmiş mənfəət');
        $pivot = $this->get(route('expenses.index', ['from' => '2024-01-01', 'to' => '2024-12-31', 'direction' => 'VALVO']));
        $this->assertNull($pivot->viewData('pivotRows')->firstWhere('category', 'Ferhad (aylıq ödəniş)'), 'Xərclər səhifəsində görünmür');

        // təkrar import (faylda yenə «Xərc / Bəli») istifadəçinin seçimini ezmir
        $this->importFile('cash', $this->fixtureDir().DIRECTORY_SEPARATOR.'08_Xercler.xlsx');
        $this->assertEqualsWithDelta(50.0, app(ProfitLossService::class)->build($filters, 'year')['lines']['founder']['2024'], 0.001);
        $this->assertEqualsWithDelta(100.0, app(ProfitLossService::class)->build($filters, 'year')['lines']['expenses']['2024'], 0.001);
    }

    public function test_expense_cell_drilldown_matches_pivot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $query = ['from' => '2024-01-01', 'to' => '2024-12-31', 'direction' => 'VALVO'];

        $pivot = $this->actingAs($admin)->get(route('expenses.index', $query))->assertOk();
        $cell = $pivot->viewData('pivotRows')->firstWhere('category', 'Əmək haqqı');
        $this->assertEqualsWithDelta(100.0, $cell->c0, 0.001);
        $pivot->assertSee(route('expenses.detail', $query + ['name' => 'Əmək haqqı', 'col' => '2024-03', 'gran' => 'month']))->assertSee('data-modal', false);

        $popup = $this->get(route('expenses.detail', $query + ['name' => 'Əmək haqqı', 'col' => '2024-03', 'gran' => 'month']), ['X-Modal' => '1']);
        $popup->assertOk()->assertDontSee('<html', false)->assertSee('05.03.2024');
        $this->assertEqualsWithDelta($cell->c0, $popup->viewData('total'), 0.001);
        $this->assertCount(1, $popup->viewData('rows'));

        // istiqamət filtri: TIR xərci VALVO xanasına düşmür, TIR-də isə görünür
        $this->assertCount(0, $this->get(route('expenses.detail', $query + ['name' => 'TIR xərcləri', 'col' => 'total']), ['X-Modal' => '1'])->viewData('rows'));
        $tir = $this->get(route('expenses.detail', ['from' => '2024-01-01', 'to' => '2024-12-31', 'direction' => 'TIR', 'name' => 'TIR xərcləri', 'col' => 'total']));
        $tir->assertOk()->assertSee('<html', false);
        $this->assertEqualsWithDelta(40.0, $tir->viewData('total'), 0.001);
    }

    public function test_reimport_does_not_duplicate_and_rollback_removes_batch(): void
    {
        $path = $this->fixtureDir().DIRECTORY_SEPARATOR.'04_Satislar.xlsx';
        $this->importFile('sales', $path, 'replace');
        $this->assertSame(7, SalesLine::query()->count());
        $this->assertSame(7, SalesOrder::query()->count());

        $appended = $this->importFile('sales', $path, 'append');
        $this->assertSame(7, SalesLine::query()->count(), 'append rejimində təbii açar dublikatı ötürür');

        $latest = ImportBatch::query()->where('file_type', 'sales')->where('status', 'done')->latest('id')->firstOrFail();
        ImporterRegistry::make('sales')->rollback($latest);
        $this->assertSame(0, SalesLine::query()->where('import_batch_id', $latest->id)->count());
        $this->assertSame('rolled_back', $latest->refresh()->status);
        $this->assertSame(0, $appended->rows_total, 'append: yeni sətir yazılmayıb');
    }

    public function test_manual_cost_edit_recalculates_everything(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = Product::query()->where('code', '100001')->firstOrFail();
        $line = DB::table('purchase_lines')->where('product_id', $a->id)->where('is_estimated', true)->first();

        $this->actingAs($admin)->put(route('products.cost', $a), ['line_id' => $line->id, 'unit_cost' => 7])->assertRedirect();
        $this->rebuild();

        $this->assertEqualsWithDelta(7.0, (float) $this->line('S1')->unit_cost, 0.0001);
        // (6×7 + 10×8) / 16 = 7,625
        $this->assertEqualsWithDelta(7.625, (float) $this->line('S2')->unit_cost, 0.0001);
    }
}
