<?php

namespace App\Services;

use App\Models\IncomingEInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesReturnDocument;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Options as ReaderOptions;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * e-qaimə (e-taxes.gov.az) mübadiləsi Excel ilə:
 *  - satış: faktura → «Təqdim etmə» nümunəsi (InvoiceBot onu e-taxes-də qaralamaya çevirir), «Əsas qeyd»-ə faktura № yazılır;
 *  - geri tutuşdurma: e-taxes-in EQF Excel çıxışında «Qeyd»-dəki faktura № ilə seriya nömrəsi və status fakturaya yazılır;
 *  - qaytarma: qaytarma qaiməsi → «Qaytarma» nümunəsi (əsas qaimənin seriyası ilə);
 *  - alış: EQF-ALIŞ çıxışı → daxil olan e-qaimələr reyestri.
 * Nümunələrin sütunları dəyişdirilmədən saxlanılır — InvoiceBot yalnız dəqiq başlıqları qəbul edir.
 */
class EInvoiceService
{
    public const SALE_HEADERS = [
        'E-qaimə qrupu', 'E-qaimə növü', 'Alıcının VÖEN-i', 'Əsas qeyd', 'Əlavə qeyd', '№',
        'Mal kodu', 'Mal adı', 'Ölçü vahidi', 'Miqdar', 'Qiymət', 'Cəmi qiyməti',
        'Aksiz dərəcəsi', 'Aksiz məbləği', 'Cəmi məbləğ', 'ƏDV-yə cəlb edilən məbləğ',
        'ƏDV-yə cəlb edilməyən məbləğ', 'ƏDV-dən azad olunan', 'ƏDV-yə 0 dərəcə ilə cəlb edilən məbləğ',
        'Ödənilməli ƏDV', 'Yol vergisi məbləği', 'Yekun məbləğ', 'Barkod', 'GTIN',
    ];

    public const RETURN_HEADERS = [
        'Əsas qaimənin seriya nömrəsi', 'Əsas qeyd ', 'Əlavə qeyd', 'Sıra №-si', 'Mal adı', 'Ölçü vahidi', 'Qaytarılan miqdar', 'Malın vəziyyəti',
    ];

    /** e-taxes Excel çıxışının (EQF-ALIŞ / EQF-SATIŞ) ilk 25 sütunu — qaimə başlığı; qalanı mal sətridir. */
    public const EQF_HEADERS = [
        '№', 'Tipi', 'Növü', 'Vəziyyəti', 'Göndərənin VÖEN-i', 'Göndərənin adı', 'Alanın VÖEN-i', 'Alanın adı',
        'Qaimənin yaranma tarixi', 'İlk təsdiqə göndərilmə tarixi', 'Son təsdiqlənmə tarixi', 'İmzalanma tarixi',
        'Seriya Nömrəsi', 'Seriya', 'Nömrəsi', 'Qeyd', 'Əlavə qeyd',
        'ƏDV-yə cəlb edilən', 'ƏDV-yə cəlb edilməyən', 'ƏDV-dən azad olan', 'ƏDV-yə 0 dərəcə ilə cəlb edilən',
        'Əsas məbləğ', 'Ödənilməli ƏDV', 'Yol vergisi', 'Yekun məbləğ',
        'Sıra №-si', 'Mal kodu', 'Unikal kod', 'Mal adı', 'Ölçü vahidi', 'Malın miqdarı', 'Malın buraxılış qiyməti', 'Cəmi qiyməti',
        'Aksiz dərəcəsi', 'Aksiz məbləği', 'Cəmi məbləğ', 'ƏDV-yə cəlb edilən məbləğ', 'ƏDV-yə cəlb edilməyən məbləğ', 'ƏDV-dən azad olunan',
        'ƏDV-yə 0 dərəcə ilə cəlb edilən məbləğ', 'Ödənilməli ƏDV', 'Yol vergisi məbləği', 'Yekun məbləğ', 'Barkod', 'Mal/Xidmət', 'Əsas qaimənin seriya nömrəsi',
    ];

    public const KIND_SALE = 'Malların, işlərin və xidmətlərin təqdim edilməsi barədə elektron qaimə-faktura';

    /** e-qaimədə yalnız 18 % dərəcə var. Qeydlərə yazılan mətnlər («Faktura № …») interfeys dilindən asılı deyil — e-taxes-ə Azərbaycanca gedir. */
    public const VAT_RATE = 18.0;

    /**
     * Fakturanın e-qaimə sətirləri və onu ixrac etməyə mane olan problemlər.
     *
     * @param  list<int>  $invoiceIds
     * @return array<int, array{doc_no: string, voen: string, lines: list<array{code: string, name: string, unit: string, qty: float, price: float, cost: float, mode: string, barcode: string}>, problems: list<string>}>
     */
    public function prepare(array $invoiceIds): array
    {
        if (! $invoiceIds) {
            return [];
        }
        $invoices = DB::table('sales_invoices as i')->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')->whereIn('i.id', $invoiceIds)
            ->get(['i.id', 'i.doc_no', 'c.voen'])->keyBy('id');
        $lines = DB::table('sales_lines as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->whereIn('l.sales_invoice_id', $invoiceIds)->where('l.op_type', 'sale')->orderBy('l.sales_invoice_id')->orderBy('l.row_no')->orderBy('l.id')
            ->get(['l.sales_invoice_id', 'l.product_code', 'l.product_name', 'l.qty', 'l.net_amount', 'l.vat_mode', 'l.vat_rate', 'l.vat_amount',
                'p.name', 'p.short_name', 'p.hs_code', 'p.barcode', 'u.name as unit'])->groupBy('sales_invoice_id');

        $out = [];
        foreach ($invoiceIds as $id) {
            $invoice = $invoices[$id] ?? null;
            if (! $invoice) {
                continue;
            }
            $problems = [];
            if (! preg_match('/^\d{10}$/', (string) $invoice->voen)) {
                $problems[] = __('Müştərinin VÖEN-i 10 rəqəm deyil');
            }
            $rows = [];
            foreach ($lines[$id] ?? [] as $l) {
                $code = preg_replace('/\D/', '', (string) $l->hs_code);
                if ($code === '') {
                    $problems[] = __(':code: məhsul kartında e-qaimə «Mal kodu» yoxdur', ['code' => $l->product_code]);
                }
                $mode = $l->vat_mode ?? 'standard';
                if ($mode === 'standard' && abs((float) $l->vat_rate - self::VAT_RATE) > 0.001) {
                    $problems[] = __(':code: ƏDV dərəcəsi :rate % — e-qaimədə yalnız 18 % var', ['code' => $l->product_code, 'rate' => (float) $l->vat_rate]);
                }
                $qty = (float) $l->qty;
                $base = round((float) $l->net_amount - (float) $l->vat_amount, 4);
                $price = $qty > 0 ? round($base / $qty, 4) : 0.0;
                $cost = round($qty * $price, 4);
                $rows[] = [
                    'code' => $code, 'name' => trim((string) ($l->short_name ?: $l->name ?: $l->product_name)), 'unit' => $l->unit ?: 'ədəd',
                    'qty' => $qty, 'price' => $price, 'cost' => $cost, 'mode' => $mode, 'barcode' => (string) $l->barcode,
                ];
            }
            if (! $rows) {
                $problems[] = __('Fakturada satış sətri yoxdur');
            }
            $out[$id] = ['doc_no' => $invoice->doc_no, 'voen' => (string) $invoice->voen, 'lines' => $rows, 'problems' => array_values(array_unique($problems))];
        }

        return $out;
    }

    /**
     * Problemsiz fakturaları «Təqdim etmə» nümunəsinə yazır və ixrac edildi kimi qeyd edir.
     *
     * @param  list<int>  $invoiceIds
     * @return array{path: string|null, exported: list<string>, skipped: array<string, list<string>>}
     */
    public function exportSales(array $invoiceIds): array
    {
        $rows = [];
        $exported = [];
        $skipped = [];
        $group = 0;
        foreach ($this->prepare($invoiceIds) as $id => $inv) {
            if ($inv['problems']) {
                $skipped[$inv['doc_no']] = $inv['problems'];

                continue;
            }
            $group++;
            foreach ($inv['lines'] as $n => $l) {
                $amounts = ['standard' => 0.0, 'not_subject' => 0.0, 'exempt' => 0.0, 'zero' => 0.0];
                $amounts[$l['mode']] = $l['cost'];
                $vat = round($amounts['standard'] * self::VAT_RATE / 100, 4);
                $rows[] = [
                    $group, self::KIND_SALE, $inv['voen'], 'Faktura № '.$inv['doc_no'], null, $n + 1,
                    $l['code'], $l['name'], $l['unit'], $l['qty'], $l['price'], $l['cost'],
                    null, null, $l['cost'], $amounts['standard'], $amounts['not_subject'] ?: null, $amounts['exempt'] ?: null, $amounts['zero'] ?: null,
                    $vat, null, round($l['cost'] + $vat, 4), $l['barcode'] ?: null, null,
                ];
            }
            $exported[$id] = $inv['doc_no'];
        }
        if (! $rows) {
            return ['path' => null, 'exported' => [], 'skipped' => $skipped];
        }
        $path = tempnam(sys_get_temp_dir(), 'eqs').'.xlsx';
        Spreadsheet::write($path, ['Sheet1' => ['headers' => self::SALE_HEADERS, 'rows' => $rows]]);
        SalesInvoice::query()->whereIn('id', array_keys($exported))->whereNull('etax_exported_at')->update(['etax_exported_at' => now()]);

        return ['path' => $path, 'exported' => array_values($exported), 'skipped' => $skipped];
    }

    /**
     * Qaytarma qaimələri → «Qaytarma» nümunəsi. Sıra №-si — malın əsas e-qaimədəki sırası (ixrac zamanı fakturanın sətir sırası).
     *
     * @param  list<int>  $returnIds
     * @return array{path: string|null, exported: list<string>, skipped: array<string, list<string>>}
     */
    public function exportReturns(array $returnIds): array
    {
        $rows = [];
        $exported = [];
        $skipped = [];
        $docs = SalesReturnDocument::query()->whereIn('id', $returnIds)->whereNull('cancelled_at')->get();
        foreach ($docs as $doc) {
            $invoice = $doc->sales_invoice_id ? SalesInvoice::query()->find($doc->sales_invoice_id) : null;
            if (! $invoice?->etax_serial) {
                $skipped[$doc->doc_no] = [__('Əsas fakturanın e-qaimə seriya nömrəsi yoxdur')];

                continue;
            }
            // ixracdakı sıra ilə eyni: fakturanın satış sətirləri row_no, id üzrə
            $order = DB::table('sales_lines')->where('sales_invoice_id', $invoice->id)->where('op_type', 'sale')->orderBy('row_no')->orderBy('id')->get(['id', 'product_id'])->values();
            $lines = DB::table('sales_return_document_lines as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
                ->where('l.sales_return_document_id', $doc->id)->orderBy('l.row_no')->get(['l.sales_line_id', 'l.product_id', 'l.qty', 'p.code', 'p.name', 'p.short_name', 'u.name as unit']);
            $problems = [];
            $docRows = [];
            foreach ($lines as $l) {
                $position = $order->search(fn ($o) => $l->sales_line_id ? (int) $o->id === (int) $l->sales_line_id : (int) $o->product_id === (int) $l->product_id);
                if ($position === false) {
                    $problems[] = __(':code fakturada yoxdur.', ['code' => $l->code]);

                    continue;
                }
                $docRows[] = [$invoice->etax_serial, 'Qaytarma № '.$doc->doc_no, null, $position + 1,
                    trim((string) ($l->short_name ?: $l->name)), $l->unit ?: 'ədəd', (float) $l->qty, in_array($doc->reason, ['damaged', 'expired', 'quality'], true) ? 'Yararsız' : 'Yararlı'];
            }
            if ($problems) {
                $skipped[$doc->doc_no] = $problems;

                continue;
            }
            array_push($rows, ...$docRows);
            $exported[$doc->id] = $doc->doc_no;
        }
        if (! $rows) {
            return ['path' => null, 'exported' => [], 'skipped' => $skipped];
        }
        $path = tempnam(sys_get_temp_dir(), 'eqr').'.xlsx';
        Spreadsheet::write($path, ['Sheet1' => ['headers' => self::RETURN_HEADERS, 'rows' => $rows]]);
        SalesReturnDocument::query()->whereIn('id', array_keys($exported))->whereNull('etax_exported_at')->update(['etax_exported_at' => now()]);

        return ['path' => $path, 'exported' => array_values($exported), 'skipped' => $skipped];
    }

    /**
     * e-taxes Excel çıxışını (EQF) oxuyur: hər mal ayrı sətirdir, başlıq təkrarlanır → seriya üzrə qaimələr.
     * Sütun adları təkrarlandığı üçün («Yekun məbləğ», «Ödənilməli ƏDV») mövqe ilə oxunur.
     *
     * @return list<array<string, mixed>>
     */
    public function readEqf(string $path): array
    {
        $reader = new Reader(new ReaderOptions(SHOULD_FORMAT_DATES: false));
        $reader->open($path);
        $invoices = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $v = array_map(fn ($c) => is_string($c) ? trim($c) : $c, $row->toArray());
                    if ($header === null) {
                        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $v);
                        $expected = array_map('mb_strtolower', array_slice(self::EQF_HEADERS, 0, 25));
                        if (array_slice($header, 0, 25) !== $expected) {
                            throw new RuntimeException(__('Fayl e-taxes-in e-qaimə Excel çıxışı (EQF) formatında deyil: ilk 25 sütun «№, Tipi, Növü, Vəziyyəti … Yekun məbləğ» olmalıdır.'));
                        }

                        continue;
                    }
                    $serial = trim((string) ($v[12] ?? ''));
                    if ($serial === '') {
                        continue;
                    }
                    $invoices[$serial] ??= [
                        'serial_number' => $serial, 'kind' => $this->str($v[1] ?? null), 'status' => $this->str($v[3] ?? null),
                        'sender_tin' => (string) Spreadsheet::code($v[4] ?? null), 'sender_name' => $this->str($v[5] ?? null),
                        'receiver_tin' => Spreadsheet::code($v[6] ?? null), 'receiver_name' => $this->str($v[7] ?? null),
                        'issued_at' => (Spreadsheet::date($v[11] ?? null) ?? Spreadsheet::date($v[8] ?? null))?->toDateString(),
                        'note' => $this->str($v[15] ?? null), 'extra_note' => $this->str($v[16] ?? null),
                        'vatable_amount' => $this->num($v[17] ?? null), 'not_subject_amount' => $this->num($v[18] ?? null),
                        'exempt_amount' => $this->num($v[19] ?? null), 'zero_rate_amount' => $this->num($v[20] ?? null),
                        'base_amount' => $this->num($v[21] ?? null), 'vat_amount' => $this->num($v[22] ?? null),
                        'road_tax' => $this->num($v[23] ?? null), 'total_amount' => $this->num($v[24] ?? null),
                        'source_serial' => $this->str($v[45] ?? null), 'items' => [],
                    ];
                    $name = $this->str($v[28] ?? null);
                    if ($name !== null) {
                        $invoices[$serial]['items'][] = [
                            'row' => (int) $this->num($v[25] ?? null), 'code' => Spreadsheet::code($v[26] ?? null), 'name' => $name,
                            'unit' => $this->str($v[29] ?? null), 'qty' => $this->num($v[30] ?? null), 'price' => $this->num($v[31] ?? null),
                            'cost' => $this->num($v[32] ?? null), 'vat' => $this->num($v[40] ?? null), 'total' => $this->num($v[42] ?? null),
                            'barcode' => Spreadsheet::code($v[43] ?? null),
                        ];
                    }
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return array_values($invoices);
    }

    /**
     * EQF-SATIŞ çıxışı → fakturalara seriya nömrəsi və status. Əsas açar: «Qeyd»-dəki faktura № (ixracda yazılır);
     * tapılmasa — alıcının VÖEN-i + yekun məbləğ + ±3 gün, yalnız tək namizəd olanda.
     *
     * @return array{matched: list<string>, unmatched: list<string>}
     */
    public function reconcileSales(string $path): array
    {
        $matched = [];
        $unmatched = [];
        $docNos = SalesInvoice::query()->pluck('id', 'doc_no');
        foreach ($this->readEqf($path) as $e) {
            $invoice = null;
            foreach (preg_split('/[\s,;:№#]+/u', (string) $e['note'].' '.(string) $e['extra_note']) as $token) {
                if ($token !== '' && isset($docNos[$token])) {
                    $invoice = SalesInvoice::query()->find($docNos[$token]);
                    break;
                }
            }
            if (! $invoice && $e['receiver_tin'] && $e['issued_at']) {
                $candidates = DB::table('sales_invoices as i')->join('customers as c', 'c.id', '=', 'i.customer_id')
                    ->where('c.voen', $e['receiver_tin'])->whereNull('i.etax_serial')->whereNull('i.cancelled_at')
                    ->whereBetween('i.date', [Carbon::parse($e['issued_at'])->subDays(3)->toDateString(), Carbon::parse($e['issued_at'])->addDays(3)->toDateString()])
                    ->whereRaw('ABS(i.total_amount - ?) < 0.05', [$e['total_amount']])->pluck('i.id');
                $invoice = $candidates->count() === 1 ? SalesInvoice::query()->find($candidates->first()) : null;
            }
            if (! $invoice) {
                $unmatched[] = $e['serial_number'];

                continue;
            }
            $invoice->forceFill(['etax_serial' => $e['serial_number'], 'etax_status' => $e['status'], 'etax_exported_at' => $invoice->etax_exported_at ?? now()])->save();
            $matched[] = $invoice->doc_no.' → '.$e['serial_number'];
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * EQF-ALIŞ çıxışı → daxil olan e-qaimələr reyestri (seriya üzrə yenilənir: status dəyişə bilər).
     *
     * @return array{created: int, updated: int}
     */
    public function importIncoming(string $path, int $userId): array
    {
        $own = preg_replace('/\D/', '', (string) config('company.voen'));
        $created = 0;
        $updated = 0;
        $suppliers = DB::table('suppliers')->whereNotNull('voen')->pluck('id', 'voen');
        foreach ($this->readEqf($path) as $e) {
            if ($own !== '' && $e['sender_tin'] === $own) {
                throw new RuntimeException(__('Bu fayl satış e-qaimələridir (göndərən bizik). Onu «Satış e-qaimələri» səhifəsində yükləyin.'));
            }
            $values = collect($e)->except('receiver_name')->all() + ['supplier_id' => $suppliers[$e['sender_tin']] ?? null];
            $existing = IncomingEInvoice::query()->where('serial_number', $e['serial_number'])->first();
            if ($existing) {
                $existing->fill(collect($values)->except($existing->supplier_id ? ['supplier_id'] : [])->all())->save();
                $updated++;
            } else {
                IncomingEInvoice::query()->create($values + ['imported_by' => $userId]);
                $created++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Daxil olan qaimənin malları → bizim məhsullar: əvvəl saxlanmış uyğunluq, sonra barkod.
     *
     * @return list<array{row: int, name: string, unit: string|null, qty: float, price: float, cost: float, product_id: int|null, factor: float}>
     */
    public function matchItems(IncomingEInvoice $invoice): array
    {
        $maps = DB::table('einvoice_product_maps')->where('sender_tin', $invoice->sender_tin)->get()->keyBy('item_name');
        $barcodes = collect($invoice->items)->pluck('barcode')->filter()->values();
        $byBarcode = $barcodes->isEmpty() ? collect() : DB::table('products')->whereIn('barcode', $barcodes->all())->pluck('id', 'barcode');

        return collect($invoice->items)->map(function ($it) use ($maps, $byBarcode) {
            $map = $maps[$it['name']] ?? null;

            return [
                'row' => (int) $it['row'], 'name' => (string) $it['name'], 'unit' => $it['unit'] ?? null,
                'qty' => (float) $it['qty'], 'price' => (float) $it['price'], 'cost' => (float) $it['cost'],
                'product_id' => $map ? (int) $map->product_id : ($it['barcode'] && isset($byBarcode[$it['barcode']]) ? (int) $byBarcode[$it['barcode']] : null),
                'factor' => $map ? (float) $map->factor : 1.0,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array{product_id: int|null, factor: float|string|null}>  $choices  sıra № => seçim
     */
    public function saveMapping(IncomingEInvoice $invoice, array $choices): void
    {
        foreach ($invoice->items as $it) {
            $choice = $choices[(int) $it['row']] ?? null;
            if (! $choice || ! $choice['product_id']) {
                continue;
            }
            DB::table('einvoice_product_maps')->updateOrInsert(['sender_tin' => $invoice->sender_tin, 'item_name' => $it['name']],
                ['product_id' => (int) $choice['product_id'], 'factor' => max(0.0001, (float) ($choice['factor'] ?: 1)), 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    /**
     * Mədaxil qaiməsi forması üçün sətirlər: miqdar × əmsal, qiymət ÷ əmsal (ƏDV-siz).
     *
     * @return Collection<int, array{product_id: int, qty: float, price: float}>
     */
    public function purchaseLines(IncomingEInvoice $invoice): Collection
    {
        return collect($this->matchItems($invoice))->filter(fn ($i) => $i['product_id'])->map(fn ($i) => [
            'product_id' => $i['product_id'], 'qty' => round($i['qty'] * $i['factor'], 4), 'price' => round($i['price'] / $i['factor'], 4),
        ])->values();
    }

    private function str(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function num(mixed $v): float
    {
        return (float) (Spreadsheet::number($v) ?? 0);
    }
}
