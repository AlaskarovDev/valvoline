<?php

namespace Tests\Feature;

use App\Http\Controllers\Reports\ProfitLossDetailController;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Manager;
use App\Models\Product;
use App\Models\PurchaseBatch;
use App\Models\SalesOrder;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Services\ProfitLossService;
use App\Support\DemoWorkbooks;
use App\Support\Fmt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsWorkbooks;
use Tests\TestCase;

/**
 * Bütün hesabat səhifələri demo məlumatla açılır, export işləyir, menecer rolu məhdudlaşdırılır.
 */
class ReportPagesTest extends TestCase
{
    use BuildsWorkbooks;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $products = [];
        foreach (range(1, 14) as $i) {
            $products[] = [(string) (870000 + $i), "Test yağı {$i}", 'Qrup '.intdiv($i, 2), $i % 3 === 0 ? 'Antifriz' : 'Mühərrik yağı', [1, 4, 5, 208][$i % 4], [12, 4, 4, 1][$i % 4], 10 + $i * 3];
        }
        $this->importFile('products', $this->productsFile($products));
        $files = (new DemoWorkbooks(42))->generate($this->fixtureDir(), 160, '2025-01-10', now()->subDays(3)->toDateString());
        $types = ['customers', 'purchases', 'sales', 'returns', 'payments', 'stock', 'cash'];
        foreach ($files as $i => $file) {
            $this->importFile($types[$i], $file);
        }
        $this->rebuild();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['dashboard'], 'orders' => ['orders.index'], 'sales' => ['sales.index'], 'margin' => ['margin.index'],
            'returns' => ['returns.index'], 'customers' => ['customers.index'], 'debts' => ['debts.index'], 'payments' => ['payments.index'],
            'stock' => ['stock.index'], 'movements' => ['movements.index'], 'purchases' => ['purchases.index'], 'expenses' => ['expenses.index'],
            'pnl' => ['pnl.index'], 'cash' => ['cash.index'], 'quality' => ['quality.index'], 'import' => ['import.index'],
            'aliases' => ['aliases.index'], 'categories' => ['categories.index'], 'users' => ['users.index'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_renders_for_admin(string $route): void
    {
        $this->actingAs($this->admin)->get(route($route, ['preset' => 'all']))->assertOk()->assertSee('Valvo Analitika');
    }

    public function test_detail_pages_and_variants_render(): void
    {
        $this->actingAs($this->admin);
        $order = SalesOrder::query()->whereNotNull('customer_id')->firstOrFail();
        $this->get(route('orders.show', $order))->assertOk()->assertSee($order->order_no);
        $this->get(route('customers.show', $order->customer_id))->assertOk()->assertSee('Akt-sverka');
        $this->get(route('movements.product', Product::query()->firstOrFail()))->assertOk()->assertSee('Mal kartı');
        $this->get(route('purchases.show', PurchaseBatch::query()->firstOrFail()))->assertOk();
        foreach (['product', 'group', 'category', 'customer', 'manager'] as $dim) {
            $this->get(route('margin.index', ['dim' => $dim, 'preset' => 'all']))->assertOk();
        }
        foreach (['manager', 'category', 'series', 'none'] as $dim) {
            $this->get(route('sales.index', ['dim' => $dim, 'gran' => 'year', 'measure' => 'liters', 'preset' => 'all']))->assertOk();
        }
        $this->get(route('pnl.index', ['gran' => 'year', 'preset' => 'all', 'direction' => 'all']))->assertOk()->assertSee('Xalis mənfəət');
        $this->get(route('customers.index', ['tab' => 'inactive', 'days' => 30]))->assertOk();
        $this->get(route('debts.index', ['tab' => 'advances']))->assertOk();
        $this->get(route('stock.index', ['asof' => '2025-06-30', 'only' => 'all']))->assertOk();
        $this->get(route('orders.index', ['from' => '2025-02-03', 'to' => '2025-05-17', 'orders_sort' => 'margin', 'orders_dir' => 'asc', 'orders_q' => 'MMC']))->assertOk();
    }

    public function test_every_pnl_number_opens_its_calculation(): void
    {
        $this->actingAs($this->admin);
        $base = ['preset' => 'all', 'direction' => 'all'];
        $filters = ReportFilters::fromRequest(request()->merge($base), 'this_year', 'VALVO');
        $report = app(ProfitLossService::class)->build($filters, 'month');

        $this->get(route('pnl.index', $base))->assertOk()->assertSee(e(route('pnl.detail', $base + ['gran' => 'month', 'line' => 'net_profit', 'col' => 'total'])), false);
        foreach (ProfitLossDetailController::LINES as $line) {
            $this->get(route('pnl.detail', $base + ['line' => $line, 'col' => 'total']), ['X-Modal' => '1'])
                ->assertOk()->assertSee('Necə hesablanır')->assertDontSee('<html', false);
        }

        // xananın nəticəsi P&L cədvəlindəki rəqəmlə eynidir
        $this->get(route('pnl.detail', $base + ['line' => 'net_profit', 'col' => 'total']), ['X-Modal' => '1'])
            ->assertSee(Fmt::money(array_sum($report['lines']['net_profit'])), false)->assertSee('Brüt mənfəət');
        $month = array_key_last(array_filter($report['lines']['sales']));
        $customer = (string) DB::table('sales_lines')->where('period', $month)->where('op_type', 'sale')->value('customer_name');
        $this->get(route('pnl.detail', $base + ['line' => 'sales', 'col' => $month]), ['X-Modal' => '1'])
            ->assertSee(Fmt::money($report['lines']['sales'][$month]), false)->assertSee('Müştərilər üzrə')->assertSee(e($customer), false);
        $this->get(route('pnl.detail', $base + ['line' => 'gross_margin', 'col' => $month]), ['X-Modal' => '1'])
            ->assertSee(Fmt::pct($report['lines']['margin'][$month]), false);

        // maya sətirləri qaimə və müştəri ilə
        $sale = DB::table('sales_lines')->where('period', $month)->where('op_type', 'sale')->orderByDesc('cogs')->first();
        $this->get(route('pnl.detail', $base + ['line' => 'sale_cogs', 'col' => $month]), ['X-Modal' => '1'])
            ->assertSee('Qaimələr üzrə sətirlər')->assertSee(e($sale->invoice_no ?? $sale->order_no), false)->assertSee(e($sale->customer_name), false);

        // kateqoriya → kassa əməliyyatları
        $category = array_key_first($report['expenses']);
        $this->get(route('pnl.detail', $base + ['line' => 'expenses', 'col' => 'total']), ['X-Modal' => '1'])
            ->assertSee(e(route('pnl.detail', $base + ['line' => 'expenses', 'col' => 'total', 'child' => $category])), false);
        $this->get(route('pnl.detail', $base + ['line' => 'expenses', 'col' => 'total', 'child' => $category]))
            ->assertOk()->assertSee('Kassa əməliyyatları')->assertSee(Fmt::money(array_sum($report['expenses'][$category])), false);
    }

    public function test_excel_and_pdf_exports(): void
    {
        $this->actingAs($this->admin);
        $xlsx = $this->get(route('orders.index', ['preset' => 'all', 'export' => 'xlsx', 'table' => 'orders']));
        $xlsx->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $xlsx->headers->get('content-disposition'));

        $pdf = $this->get(route('margin.index', ['preset' => 'all', 'export' => 'pdf', 'table' => 'margin']));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $this->get(route('pnl.index', ['preset' => 'all', 'export' => 'xlsx']))->assertOk();
        $this->get(route('pnl.index', ['preset' => 'all', 'export' => 'pdf']))->assertOk();
    }

    public function test_manager_sees_only_own_data_and_no_admin_pages(): void
    {
        $manager = Manager::query()->orderBy('id')->firstOrFail();
        $user = User::factory()->create(['role' => 'menecer', 'manager_id' => $manager->id]);
        $this->actingAs($user);

        foreach (['purchases.index', 'expenses.index', 'pnl.index', 'cash.index', 'import.index', 'users.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }

        $foreignOrder = SalesOrder::query()->where('manager_id', '!=', $manager->id)->firstOrFail();
        $this->get(route('orders.show', $foreignOrder))->assertForbidden();
        $foreignCustomer = Customer::query()->where('manager_id', '!=', $manager->id)->firstOrFail();
        $this->get(route('customers.show', $foreignCustomer))->assertForbidden();

        // ?manager=başqası parametri məhdudiyyəti aşa bilmir
        $other = Manager::query()->where('id', '!=', $manager->id)->firstOrFail();
        $response = $this->get(route('orders.index', ['preset' => 'all', 'manager' => $other->id]))->assertOk();
        $own = DB::table('sales_orders')->where('manager_id', $manager->id)->count();
        $response->assertSee(number_format($own, 0, ',', ' ').' sətir', false);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Xalis mənfəət');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_import_preview_confirm_and_rollback_flow(): void
    {
        $this->actingAs($this->admin);
        $path = $this->fixtureDir().DIRECTORY_SEPARATOR.'06_Odenisler.xlsx';
        $file = new UploadedFile($path, '06_Odenisler.xlsx', null, null, true);
        $response = $this->post(route('import.upload'), ['file' => $file]);
        $batch = ImportBatch::query()->where('status', 'preview')->latest('id')->firstOrFail();
        $response->assertRedirect(route('import.preview', $batch));
        $this->assertSame('payments', $batch->file_type);

        $this->get(route('import.preview', $batch))->assertOk()->assertSee('Odenisler')->assertSee('Köhnəni sil');
        $before = DB::table('payments')->count();
        $this->post(route('import.confirm', $batch), ['mode' => 'replace'])->assertRedirect(route('import.index'));
        $this->assertSame($before, DB::table('payments')->count(), 'replace: köhnə partiya silinib, yenisi yazılıb');
        $this->assertSame(1, ImportBatch::query()->where('file_type', 'payments')->where('status', 'done')->count());

        $this->post(route('import.rollback', $batch->refresh()))->assertRedirect();
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_upload_rejects_non_xlsx(): void
    {
        $this->actingAs($this->admin);
        $fake = UploadedFile::fake()->createWithContent('04_Satislar.xlsx', 'not a zip');
        $this->post(route('import.upload'), ['file' => $fake])->assertSessionHasErrors('file');
    }
}
