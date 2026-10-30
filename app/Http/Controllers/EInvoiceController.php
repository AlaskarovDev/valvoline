<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Models\IncomingEInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Reports\Table;
use App\Services\EInvoiceService;
use App\Services\NumberingService;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * e-qaimə: satış fakturalarının ixracı və geri tutuşdurulması, daxil olan (alış) e-qaimələr reyestri.
 */
class EInvoiceController extends ReportController
{
    public function sales(Request $request, EInvoiceService $einvoices): View|Response
    {
        $filters = $this->filters($request, 'this_month');
        $tab = in_array($request->query('tab'), ['exported', 'linked', 'returns'], true) ? $request->query('tab') : 'pending';
        $manager = $request->user()->restrictedManagerId();

        if ($tab === 'returns') {
            $query = DB::table('sales_return_documents as r')->leftJoin('sales_invoices as i', 'i.id', '=', 'r.sales_invoice_id')
                ->leftJoin('customers as c', 'c.id', '=', 'r.customer_id')->whereNull('r.cancelled_at')
                ->whereBetween('r.date', [$filters->fromDate(), $filters->toDate()])
                ->when($manager !== null, fn ($q) => $q->where('r.manager_id', $manager))
                ->select('r.id', 'r.date', 'r.doc_no', 'r.customer_id', 'c.name as customer_name', 'r.total_amount', 'r.etax_exported_at', 'i.id as invoice_id', 'i.doc_no as invoice_no', 'i.etax_serial');
            $table = Table::make('eqr', __('Qaytarma qaimələri'))
                ->column('date', __('Tarix'), 'date', ['sql' => 'r.date'])
                ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 'r.doc_no', 'link' => fn ($r) => route('sales-returns.show', $r->id)])
                ->column('customer_name', __('Müştəri'), 'text', ['sql' => 'c.name'])
                ->column('invoice_no', __('Faktura'), 'text', ['sql' => 'i.doc_no', 'link' => fn ($r) => $r->invoice_id ? route('invoices.show', $r->invoice_id) : null])
                ->column('etax_serial', __('Əsas e-qaimə'), 'text', ['sql' => 'i.etax_serial', 'format' => fn ($v) => $v ? '<span class="font-mono">'.e($v).'</span>' : '<span class="badge badge-warn">'.e(__('seriya yoxdur')).'</span>'])
                ->column('total_amount', __('Məbləğ'), 'money', ['sql' => 'r.total_amount'])
                ->column('etax_exported_at', __('İxrac'), 'text', ['sql' => 'r.etax_exported_at', 'format' => fn ($v) => $v ? e(Fmt::date(substr((string) $v, 0, 10))) : '—'])
                ->source($query)->sortBy('date', 'desc');
            $problems = [];
        } else {
            $query = DB::table('sales_invoices as i')->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')->whereNull('i.cancelled_at')
                ->whereBetween('i.date', [$filters->fromDate(), $filters->toDate()])
                ->when($manager !== null, fn ($q) => $q->where('i.manager_id', $manager))
                ->when($tab === 'pending', fn ($q) => $q->whereNull('i.etax_serial')->whereNull('i.etax_exported_at'))
                ->when($tab === 'exported', fn ($q) => $q->whereNull('i.etax_serial')->whereNotNull('i.etax_exported_at'))
                ->when($tab === 'linked', fn ($q) => $q->whereNotNull('i.etax_serial'))
                ->select('i.id', 'i.date', 'i.doc_no', 'i.customer_id', 'c.name as customer_name', 'c.voen', 'i.total_amount', 'i.vat_amount', 'i.etax_serial', 'i.etax_status', 'i.etax_exported_at');
            $problems = $tab === 'linked' ? [] : collect($einvoices->prepare((clone $query)->pluck('i.id')->map(fn ($id) => (int) $id)->all()))->map(fn ($p) => $p['problems'])->filter()->all();
            $table = Table::make('eqs', __('Satış fakturaları'))
                ->column('date', __('Tarix'), 'date', ['sql' => 'i.date'])
                ->column('doc_no', __('Faktura'), 'text', ['sql' => 'i.doc_no', 'link' => fn ($r) => route('invoices.show', $r->id)])
                ->column('customer_name', __('Müştəri'), 'text', ['sql' => 'c.name', 'link' => fn ($r) => $r->customer_id ? route('customers.show', $r->customer_id) : null])
                ->column('voen', __('VÖEN'), 'text', ['sql' => 'c.voen', 'mobile' => false])
                ->column('total_amount', __('Məbləğ'), 'money', ['sql' => 'i.total_amount'])
                ->column('vat_amount', __('ƏDV'), 'money', ['sql' => 'i.vat_amount', 'mobile' => false])
                ->column('etax', __('e-qaimə'), 'text', ['sortable' => false, 'format' => fn ($v, $r) => $r->etax_serial
                    ? '<span class="font-mono">'.e($r->etax_serial).'</span>'.($r->etax_status ? ' <span class="text-xs text-muted">'.e($r->etax_status).'</span>' : '')
                    : ($r->etax_exported_at ? '<span class="badge">'.e(__('ixrac edilib :date', ['date' => Fmt::date(substr((string) $r->etax_exported_at, 0, 10))])).'</span>' : '<span class="text-muted">—</span>')])
                ->column('problems', __('Problem'), 'text', ['sortable' => false, 'wrap' => true, 'format' => fn ($v, $r) => isset($problems[$r->id])
                    ? '<span class="text-xs neg">'.collect($problems[$r->id])->map(fn ($p) => e($p))->implode('<br>').'</span>' : '<span class="pos text-xs">'.e(__('hazırdır')).'</span>'])
                ->source($query)->sortBy('date', 'desc');
        }

        if ($response = $this->exportIfRequested($request, [$table], __('Satış e-qaimələri'), $filters)) {
            return $response;
        }
        $counts = DB::table('sales_invoices')->whereNull('cancelled_at')->whereBetween('date', [$filters->fromDate(), $filters->toDate()])
            ->when($manager !== null, fn ($q) => $q->where('manager_id', $manager))
            ->selectRaw('SUM(CASE WHEN etax_serial IS NULL AND etax_exported_at IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN etax_serial IS NULL AND etax_exported_at IS NOT NULL THEN 1 ELSE 0 END) AS exported,
                SUM(CASE WHEN etax_serial IS NOT NULL THEN 1 ELSE 0 END) AS linked')->first();

        return view('einvoices.sales', ['title' => __('Satış e-qaimələri'), 'filters' => $filters, 'tab' => $tab, 'table' => $table, 'rows' => $table->resolve($request),
            'counts' => $counts, 'ready' => $tab === 'pending' ? (int) $counts->pending - count($problems) : null]);
    }

    /**
     * Seçilmiş dövrün göndərilməmiş (və ya ?ids[] ilə verilmiş) fakturaları → «Təqdim etmə» Excel nümunəsi.
     */
    public function export(Request $request, EInvoiceService $einvoices): BinaryFileResponse|RedirectResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $manager = $request->user()->restrictedManagerId();
        $ids = DB::table('sales_invoices')->whereNull('cancelled_at')->whereNull('etax_serial')
            ->when($manager !== null, fn ($q) => $q->where('manager_id', $manager))
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']),
                fn ($q) => $q->whereNull('etax_exported_at')->whereDate('date', '>=', $data['from'] ?? now()->startOfMonth()->toDateString())->whereDate('date', '<=', $data['to'] ?? now()->toDateString()))
            ->orderBy('date')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $result = $einvoices->exportSales($ids);
        if ($result['path'] === null) {
            return back()->with('error', $result['skipped']
                ? __('İxrac ediləcək problemsiz faktura yoxdur.').' '.collect($result['skipped'])->map(fn ($p, $no) => $no.': '.implode('; ', $p))->take(5)->implode(' · ')
                : __('İxrac ediləcək faktura yoxdur.'));
        }

        return response()->download($result['path'], 'e-qaime-satis-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend();
    }

    public function exportReturns(Request $request, EInvoiceService $einvoices): BinaryFileResponse|RedirectResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $manager = $request->user()->restrictedManagerId();
        $ids = DB::table('sales_return_documents')->whereNull('cancelled_at')
            ->when($manager !== null, fn ($q) => $q->where('manager_id', $manager))
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']),
                fn ($q) => $q->whereNull('etax_exported_at')->whereDate('date', '>=', $data['from'] ?? now()->startOfMonth()->toDateString())->whereDate('date', '<=', $data['to'] ?? now()->toDateString()))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $result = $einvoices->exportReturns($ids);
        if ($result['path'] === null) {
            return back()->with('error', $result['skipped']
                ? __('İxrac ediləcək qaytarma yoxdur.').' '.collect($result['skipped'])->map(fn ($p, $no) => $no.': '.implode('; ', $p))->take(5)->implode(' · ')
                : __('İxrac ediləcək qaytarma yoxdur.'));
        }

        return response()->download($result['path'], 'e-qaime-qaytarma-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend();
    }

    /**
     * e-taxes-in satış e-qaimələri Excel-i → fakturalara seriya nömrəsi və status.
     */
    public function reconcile(Request $request, EInvoiceService $einvoices): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:20480']]);
        try {
            $result = $einvoices->reconcileSales($request->file('file')->getRealPath());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('popup', ['title' => __('e-qaimələr tutuşduruldu'),
            'message' => __(':matched faktura e-qaimə ilə əlaqələndirildi, :unmatched e-qaimə tapılmadı.', ['matched' => count($result['matched']), 'unmatched' => count($result['unmatched'])])
                .($result['unmatched'] ? ' '.__('Tapılmayanlar: :list', ['list' => implode(', ', array_slice($result['unmatched'], 0, 10))]) : '')]);
    }

    /**
     * Fakturaya e-qaimə seriya nömrəsini əl ilə yazmaq (və ya silmək).
     */
    public function serial(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $invoice->manager_id !== $restricted, 403);
        $data = $request->validate(['etax_serial' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}\d{6,}$/', Rule::unique('sales_invoices', 'etax_serial')->ignore($invoice->id)]],
            ['etax_serial.regex' => __('Seriya nömrəsi «MT2610…» kimi olmalıdır: 2 hərf və rəqəmlər.')], ['etax_serial' => __('e-qaimə seriya nömrəsi')]);
        $serial = isset($data['etax_serial']) ? strtoupper($data['etax_serial']) : null;
        $invoice->forceFill(['etax_serial' => $serial, 'etax_status' => $serial ? $invoice->etax_status : null])->save();

        return back()->with('status', $serial ? __('e-qaimə seriya nömrəsi yazıldı: :serial', ['serial' => $serial]) : __('e-qaimə seriya nömrəsi silindi.'));
    }

    public function incoming(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_year');
        $tab = in_array($request->query('tab'), ['linked', 'all'], true) ? $request->query('tab') : 'new';
        $query = DB::table('incoming_einvoices as e')->leftJoin('purchase_documents as d', fn ($j) => $j->on('d.id', '=', 'e.purchase_document_id')->whereNull('d.cancelled_at'))
            ->whereBetween('e.issued_at', [$filters->fromDate(), $filters->toDate()])
            ->when($tab === 'new', fn ($q) => $q->whereNull('d.id'))
            ->when($tab === 'linked', fn ($q) => $q->whereNotNull('d.id'))
            ->select('e.id', 'e.issued_at', 'e.serial_number', 'e.kind', 'e.status', 'e.sender_tin', 'e.sender_name', 'e.base_amount', 'e.vat_amount', 'e.total_amount', 'd.id as doc_id', 'd.doc_no');
        $table = Table::make('eqa', __('Daxil olan e-qaimələr'))
            ->column('issued_at', __('Tarix'), 'date', ['sql' => 'e.issued_at'])
            ->column('serial_number', __('Seriya №'), 'text', ['sql' => 'e.serial_number', 'link' => fn ($r) => route('einvoices.incoming.show', $r->id), 'class' => fn () => 'font-mono'])
            ->column('sender_name', __('Göndərən'), 'text', ['sql' => 'e.sender_name', 'wrap' => true])
            ->column('sender_tin', __('VÖEN'), 'text', ['sql' => 'e.sender_tin', 'mobile' => false])
            ->column('status', __('Vəziyyəti'), 'text', ['sql' => 'e.status', 'mobile' => false])
            ->column('base_amount', __('ƏDV-siz'), 'money', ['sql' => 'e.base_amount', 'mobile' => false])
            ->column('vat_amount', __('ƏDV'), 'money', ['sql' => 'e.vat_amount'])
            ->column('total_amount', __('Yekun'), 'money', ['sql' => 'e.total_amount'])
            ->column('doc_no', __('Mədaxil qaiməsi'), 'text', ['sql' => 'd.doc_no', 'link' => fn ($r) => $r->doc_id ? route('purchase-docs.show', $r->doc_id) : null,
                'format' => fn ($v) => $v ? e($v) : '<span class="badge badge-warn">'.e(__('yaradılmayıb')).'</span>'])
            ->source($query)->sortBy('issued_at', 'desc')->footer(auto: true);

        if ($response = $this->exportIfRequested($request, [$table], __('Daxil olan e-qaimələr'), $filters)) {
            return $response;
        }

        return view('einvoices.incoming', ['title' => __('Alış e-qaimələri'), 'filters' => $filters, 'tab' => $tab, 'table' => $table, 'rows' => $table->resolve($request)]);
    }

    public function import(Request $request, EInvoiceService $einvoices): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:20480']]);
        try {
            $result = $einvoices->importIncoming($request->file('file')->getRealPath(), $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('popup', ['title' => __('e-qaimələr yükləndi'),
            'message' => __(':created yeni e-qaimə əlavə edildi, :updated mövcud e-qaimə yeniləndi.', $result)]);
    }

    public function show(IncomingEInvoice $incomingEInvoice, EInvoiceService $einvoices): View
    {
        $invoice = $incomingEInvoice->load('supplier', 'purchaseDocument');

        return view('einvoices.incoming-show', [
            'title' => __('Alış e-qaiməsi').' № '.$invoice->serial_number, 'invoice' => $invoice, 'items' => $einvoices->matchItems($invoice),
            'products' => DB::table('products')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'linked' => $invoice->purchaseDocument && ! $invoice->purchaseDocument->isCancelled() ? $invoice->purchaseDocument : null,
        ]);
    }

    /**
     * Malları məhsullara uyğunlaşdırır (yadda saxlanır), təchizatçını VÖEN ilə tapır / yaradır və mədaxil qaiməsi formasını açır.
     */
    public function map(Request $request, IncomingEInvoice $incomingEInvoice, EInvoiceService $einvoices, NumberingService $numbering): RedirectResponse
    {
        $invoice = $incomingEInvoice;
        if ($invoice->isVoid()) {
            return back()->with('error', __('Ləğv edilmiş e-qaimədən mədaxil yaradılmır.'));
        }
        $data = $request->validate(['items' => ['array'], 'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'], 'items.*.factor' => ['nullable', 'numeric', 'gt:0', 'max:100000']]);
        $einvoices->saveMapping($invoice, $data['items'] ?? []);

        if (! $invoice->supplier_id) {
            $supplier = Supplier::query()->where('voen', $invoice->sender_tin)->first()
                ?? Supplier::query()->create(['code' => $numbering->resolve('supplier', null, ['workplace_id' => $request->user()->workplace_id]),
                    'name' => $invoice->sender_name ?: $invoice->sender_tin, 'voen' => $invoice->sender_tin, 'currency' => 'AZN', 'is_active' => true, 'created_by' => $request->user()->id]);
            $invoice->forceFill(['supplier_id' => $supplier->id])->save();
        }

        return redirect()->route('purchase-docs.create', ['einvoice' => $invoice->id]);
    }
}
