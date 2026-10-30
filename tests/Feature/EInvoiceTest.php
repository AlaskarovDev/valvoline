<?php

namespace Tests\Feature;

use App\Models\IncomingEInvoice;
use App\Models\PurchaseDocument;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EInvoiceService;
use App\Support\Spreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * e-qaimə: satış fakturasının «Təqdim etmə» nümunəsinə ixracı, EQF ilə seriya tutuşdurması, qaytarma ixracı
 * və daxil olan e-qaimədən mədaxil qaiməsi.
 */
class EInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private int $wh;

    private int $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->wh = (int) Warehouse::query()->where('code', 'A2')->value('id');
        $unit = (int) DB::table('units')->where('name', 'ədəd')->value('id');
        $this->oil = DB::table('products')->insertGetId(['code' => 'OIL', 'name' => 'Valvoline MaxLife 5W30 4L', 'hs_code' => '2710198100', 'barcode' => '4820001',
            'base_unit_id' => $unit, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_lines')->insert(['batch_no' => 1, 'date' => now()->subMonth()->toDateString(), 'period' => now()->subMonth()->format('Y-m'), 'product_id' => $this->oil,
            'product_code' => 'OIL', 'qty' => 50, 'unit_cost' => 30, 'total_cost' => 1500, 'row_no' => 1, 'warehouse_id' => $this->wh, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_sales_invoice_exports_to_template_reconciles_serial_and_exports_return(): void
    {
        $good = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj Elvin', 'voen' => '1234567890', 'created_at' => now(), 'updated_at' => now()]);
        $noVoen = DB::table('customers')->insertGetId(['code' => 'C2', 'name' => 'Fiziki şəxs', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$good, $noVoen] as $customer) {
            $this->post(route('orders.store'), ['date' => now()->toDateString(), 'customer_id' => $customer, 'warehouse_id' => $this->wh, 'then' => 'invoice',
                'lines' => [['product_id' => $this->oil, 'op_type' => 'sale', 'qty' => 3, 'price' => 118, 'discount' => 0]]])->assertSessionHasNoErrors();
        }
        [$invoice, $blocked] = SalesInvoice::query()->orderBy('id')->get()->all();

        $this->get(route('einvoices.sales'))->assertOk()->assertSee($invoice->doc_no)->assertSee('hazırdır')->assertSee('Müştərinin VÖEN-i 10 rəqəm deyil')
            ->assertSee('Hazır 1 fakturanı Excel-ə ixrac et');

        $response = $this->post(route('einvoices.export'), ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);
        $response->assertOk();
        $rows = $this->read($response->baseResponse->getFile()->getPathname());
        $this->assertSame(EInvoiceService::SALE_HEADERS, $rows[0]);
        $this->assertCount(2, $rows, 'yalnız VÖEN-i olan faktura ixrac olunur');
        // 118 ₼ ƏDV daxil → 100 ₼ ƏDV-siz; 3 × 100 = 300, ƏDV 54
        $this->assertSame([1, EInvoiceService::KIND_SALE, '1234567890', 'Faktura № '.$invoice->doc_no, 1, '2710198100', 'Valvoline MaxLife 5W30 4L', 'ədəd'],
            [$rows[1][0], $rows[1][1], $rows[1][2], $rows[1][3], $rows[1][5], $rows[1][6], $rows[1][7], $rows[1][8]]);
        $this->assertEquals([3, 100, 300, 300, 300, 54, 354, '4820001'], [$rows[1][9], $rows[1][10], $rows[1][11], $rows[1][14], $rows[1][15], $rows[1][19], $rows[1][21], $rows[1][22]]);
        $this->assertNotNull($invoice->fresh()->etax_exported_at);
        $this->assertNull($blocked->fresh()->etax_exported_at);

        // e-taxes-in satış çıxışı: «Qeyd»-də faktura № → seriya fakturaya yazılır
        $eqf = $this->eqf([[1, 'Təqdim etmə', 'Cari', 'Təsdiqləndi', '9900000001', 'Valvo MMC', '1234567890', 'Qaraj Elvin', now()->format('d.m.Y H:i'), '', '', now()->format('d.m.Y H:i'),
            'MT2610000123', 'MT2610', '000123', 'Faktura № '.$invoice->doc_no, '', 300, 0, 0, 0, 300, 54, 0, 354,
            1, '2710198100', 'MT2610000123-00001', 'Valvoline MaxLife 5W30 4L', 'ədəd', 3, 100, 300, '', '', 300, 300, '', '', '', 54, '', 354, '4820001', 'Mal', '']]);
        $this->post(route('einvoices.reconcile'), ['file' => $eqf])->assertSessionHas('popup', fn ($p) => str_starts_with($p['message'], '1 faktura'));
        $this->assertSame(['MT2610000123', 'Təsdiqləndi'], [$invoice->fresh()->etax_serial, $invoice->fresh()->etax_status]);
        $this->get(route('invoices.show', $invoice))->assertSee('MT2610000123');
        $this->get(route('einvoices.sales', ['tab' => 'linked']))->assertSee('MT2610000123');

        // qaytarma: əsas e-qaimənin seriyası və malın sıra №-si ilə
        $line = DB::table('sales_lines')->where('sales_invoice_id', $invoice->id)->first();
        $this->post(route('sales-returns.store'), ['date' => now()->toDateString(), 'sales_invoice_id' => $invoice->id, 'customer_id' => $good, 'warehouse_id' => $this->wh,
            'reason' => 'wrong_item', 'lines' => [['product_id' => $this->oil, 'sales_line_id' => $line->id, 'qty' => 1, 'price' => 1]]])->assertSessionHas('popup');
        $return = DB::table('sales_return_documents')->first();
        $rows = $this->read($this->post(route('einvoices.export-returns'), ['ids' => [$return->id]])->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertSame(EInvoiceService::RETURN_HEADERS, $rows[0]);
        $this->assertEquals(['MT2610000123', 'Qaytarma № '.$return->doc_no, '', 1, 'Valvoline MaxLife 5W30 4L', 'ədəd', 1, 'Yararlı'], $rows[1]);

        // seriyanı əl ilə düzəltmək; format yoxlanır
        $this->post(route('einvoices.serial', $invoice), ['etax_serial' => 'abc'])->assertSessionHasErrors('etax_serial');
        $this->post(route('einvoices.serial', $invoice), ['etax_serial' => 'mt2610000999'])->assertSessionHas('status');
        $this->assertSame('MT2610000999', $invoice->fresh()->etax_serial);
    }

    public function test_incoming_einvoice_maps_products_and_creates_purchase_receipt(): void
    {
        $antifreeze = DB::table('products')->insertGetId(['code' => 'ANT', 'name' => 'Antifriz G12 1L', 'created_at' => now(), 'updated_at' => now()]);
        $head = ['Təqdim etmə', 'Cari', 'Təsdiqləndi', '9988776655', 'Neft Təchizat MMC', '9900000001', 'Valvo MMC', '20.10.2026 10:00', '', '', '20.10.2026 10:05',
            'MT2610000777', 'MT2610', '000777', 'Müqavilə 5', '', 700, 0, 0, 0, 700, 126, 0, 826];
        $file = fn () => $this->eqf([
            [1, ...$head, 1, '2710198100', 'X1', 'MaxLife 5W30 4L', 'ədəd', 10, 50, 500, '', '', 500, 500, '', '', '', 90, '', 590, '4820001', 'Mal', ''],
            [1, ...$head, 2, '3820000000', 'X2', 'Antifriz G12 (qutu)', 'qutu', 2, 100, 200, '', '', 200, 200, '', '', '', 36, '', 236, '', 'Mal', ''],
        ]);
        $this->post(route('einvoices.import'), ['file' => $file()])->assertSessionHas('popup', fn ($p) => str_starts_with($p['message'], '1 yeni'));
        $this->post(route('einvoices.import'), ['file' => $file()])->assertSessionHas('popup', fn ($p) => str_contains($p['message'], '1 mövcud'));
        $incoming = IncomingEInvoice::query()->sole();
        $this->assertSame(['9988776655', '2026-10-20', 126.0, 2], [$incoming->sender_tin, $incoming->issued_at->toDateString(), (float) $incoming->vat_amount, count($incoming->items)]);

        // barkodla tapılan mal hazır seçilir, ikincisi əl ilə (1 qutu = 12 ədəd)
        $this->get(route('einvoices.incoming'))->assertOk()->assertSee('MT2610000777')->assertSee('yaradılmayıb');
        $this->get(route('einvoices.incoming.show', $incoming))->assertOk()->assertSee('Antifriz G12 (qutu)')
            ->assertSee('<option value="'.$this->oil.'" selected', false);
        $this->post(route('einvoices.incoming.map', $incoming), ['items' => [1 => ['product_id' => $this->oil, 'factor' => 1], 2 => ['product_id' => $antifreeze, 'factor' => 12]]])
            ->assertRedirect(route('purchase-docs.create', ['einvoice' => $incoming->id]));
        $supplier = DB::table('suppliers')->where('voen', '9988776655')->first();
        $this->assertSame(['Neft Təchizat MMC', 'AZN'], [$supplier->name, $supplier->currency]);

        $form = $this->get(route('purchase-docs.create', ['einvoice' => $incoming->id]))->assertOk()->assertSee('value="MT2610000777"', false);
        $this->assertEquals([['product_id' => $this->oil, 'qty' => 10, 'price' => 50], ['product_id' => $antifreeze, 'qty' => 24, 'price' => 8.3333]],
            $form->viewData('config')['lines']);

        $this->post(route('purchase-docs.store'), ['type' => 'receipt', 'date' => '2026-10-20', 'supplier_id' => $supplier->id, 'warehouse_id' => $this->wh, 'incoming_einvoice_id' => $incoming->id,
            'supplier_invoice_no' => 'MT2610000777', 'lines' => $form->viewData('config')['lines']])->assertSessionHas('popup');
        $doc = PurchaseDocument::query()->sole();
        $this->assertSame($doc->id, $incoming->fresh()->purchase_document_id);
        $this->get(route('einvoices.incoming.show', $incoming))->assertSee('Bu e-qaimədən mədaxil artıq yaradılıb.');

        // uyğunluq yadda qalıb: növbəti qaimədə əl ilə seçmək lazım deyil
        $this->assertSame([$this->oil, $antifreeze], array_column(app(EInvoiceService::class)->matchItems($incoming), 'product_id'));
    }

    public function test_wrong_file_is_rejected_with_a_clear_message(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        Spreadsheet::write($path, ['Sheet1' => ['headers' => ['Kod', 'Ad'], 'rows' => [['1', 'x']]]]);
        $this->post(route('einvoices.import'), ['file' => new UploadedFile($path, 'bad.xlsx', null, null, true)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'EQF'));
        $this->assertSame(0, IncomingEInvoice::query()->count());
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function eqf(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'eqf').'.xlsx';
        Spreadsheet::write($path, ['Sheet1' => ['headers' => EInvoiceService::EQF_HEADERS, 'rows' => $rows]]);

        return new UploadedFile($path, 'EQF.xlsx', null, null, true);
    }

    /**
     * @return list<list<mixed>>
     */
    private function read(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $out[] = array_map(fn ($v) => $v === null ? '' : $v, $row->toArray());
            }
            break;
        }
        $reader->close();

        return $out;
    }
}
