<?php

namespace Tests\Feature;

use App\Imports\ImporterRegistry;
use App\Models\Product;
use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Services\StockService;
use App\Support\Spreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWorkbooks;
use Tests\TestCase;

/**
 * Qəbul testləri: storage/import qovluğundakı REAL 01–08 faylları import edilir və nəticə
 * promptdakı nəzarət rəqəmləri ilə (±1 AZN) müqayisə olunur. Fayllar yoxdursa test ötürülür.
 * Qovluğu dəyişmək üçün: ACCEPTANCE_DIR=... php artisan test --filter=AcceptanceTest
 */
class AcceptanceTest extends TestCase
{
    use BuildsWorkbooks;
    use RefreshDatabase;

    private static string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        self::$dir = (string) (getenv('ACCEPTANCE_DIR') ?: storage_path('import'));
        foreach (ImporterRegistry::all() as $importer) {
            if (! is_file(self::$dir.DIRECTORY_SEPARATOR.$importer->fileHint())) {
                $this->markTestSkipped($importer->fileHint().' '.self::$dir.' qovluğunda yoxdur — qəbul testi ötürülür.');
            }
        }
        foreach (ImporterRegistry::all() as $type => $importer) {
            $batch = $this->importFile($type, self::$dir.DIRECTORY_SEPARATOR.$importer->fileHint());
            $this->assertSame('done', $batch->status);
        }
        $this->rebuild();
    }

    public function test_control_figures_from_prompt(): void
    {
        $all = new ReportFilters(Carbon::parse('2000-01-01'), Carbon::parse('2100-01-01'), 'all');
        $totals = SalesSummaryQuery::totals($all);

        $this->assertSame(14537, DB::table('sales_lines')->count(), 'satış sətirləri');
        $this->assertSame(3903, DB::table('sales_orders')->count(), 'sifariş sayı');
        $this->assertEqualsWithDelta(2338527.68, $totals->sales, 1.0, 'ümumi satış');
        $this->assertEqualsWithDelta(295781.32, $totals->returns, 1.0, 'qaytarma');
        $this->assertEqualsWithDelta(2042746.36, $totals->net_sales, 1.0, 'xalis satış');
        $this->assertEqualsWithDelta(1316567, $totals->cogs, 1316567 * 0.005, 'satılan malın mayası (≈)');
        $this->assertEqualsWithDelta(0.355, $totals->margin, 0.005, 'brüt marja (≈)');

        $debt = (float) DB::table('customer_balances as b')->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->where('c.type', 'customer')->sum('b.balance');
        $this->assertEqualsWithDelta(540539.02, $debt, 1.0, 'xalis debitor borc');

        $stock = app(StockService::class)->balances($all, Carbon::parse('2026-10-01')->endOfDay());
        $this->assertEqualsWithDelta(10890, $stock->sum('qty'), 0.5, 'anbar qalığı, ədəd');
        $this->assertEqualsWithDelta(248960, $stock->sum('value'), 248960 * 0.005, 'anbar dəyəri (≈)');
    }

    /**
     * 07_Anbar.xlsx / Cari_Qaliq_Yoxlama: mal-mal qalıq müqayisəsi.
     * Sütunlar: Mal_Kodu + adında «Qaliq» olan ilk rəqəm sütunu.
     */
    public function test_stock_per_product_matches_check_sheet(): void
    {
        $path = self::$dir.DIRECTORY_SEPARATOR.'07_Anbar.xlsx';
        $rows = iterator_to_array(Spreadsheet::rows($path, 'Cari_Qaliq_Yoxlama'), false);
        $this->assertNotEmpty($rows, 'Cari_Qaliq_Yoxlama boşdur');
        $qtyColumn = collect(array_keys($rows[0]))->first(fn ($h) => $h !== 'Mal_Kodu' && stripos($h, 'qaliq') !== false);
        $this->assertNotNull($qtyColumn, 'Qalıq sütunu tapılmadı: '.implode(', ', array_keys($rows[0])));

        $mismatches = [];
        foreach ($rows as $row) {
            $code = Spreadsheet::code($row['Mal_Kodu'] ?? null);
            $expected = Spreadsheet::number($row[$qtyColumn] ?? null);
            if ($code === null || $expected === null) {
                continue;
            }
            $actual = (float) Product::query()->where('code', $code)->value('stock_qty');
            if (abs($actual - $expected) > 0.5) {
                $mismatches[] = "{$code}: gözlənilən {$expected}, sistem {$actual}";
            }
        }
        $this->assertSame([], $mismatches, count($mismatches).' mal uyğun gəlmir');
    }
}
