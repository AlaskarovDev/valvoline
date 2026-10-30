<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Jobs\RebuildAnalytics;
use App\Models\IncomingEInvoice;
use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\Workplace;
use App\Reports\Table;
use App\Services\AnalyticsRebuilder;
use App\Services\CurrencyRates;
use App\Services\EInvoiceService;
use App\Services\PurchaseService;
use App\Support\Fmt;
use App\Support\Vat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alış → Mədaxil qaimələri və təchizatçıya qaytarma.
 */
class PurchaseDocumentController extends ReportController
{
    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_year');
        $types = PurchaseDocument::typeLabels();
        $type = array_key_exists((string) $request->query('type'), $types) ? $request->query('type') : null;
        $query = $this->applyState($request, DB::table('purchase_documents as d'), 'd')
            ->join('suppliers as s', 's.id', '=', 'd.supplier_id')->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->whereBetween('d.date', [$filters->fromDate(), $filters->toDate()])
            ->when($type, fn ($q) => $q->where('d.type', $type))
            ->when(is_numeric($request->query('supplier')), fn ($q) => $q->where('d.supplier_id', (int) $request->query('supplier')))
            ->select('d.id', 'd.doc_no', 'd.type', 'd.date', 'd.due_date', 'd.currency', 'd.total_amount', 'd.total_cost_azn', 'd.supplier_invoice_no',
                'd.supplier_id', 's.name as supplier_name', 'w.name as warehouse_name');
        $table = Table::make('purchasedocs', $type ? $types[$type] : __('Alış sənədləri'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'd.date'])
            ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 'd.doc_no', 'link' => fn ($r) => route('purchase-docs.show', $r->id)])
            ->column('type', __('Növ'), 'text', ['sql' => 'd.type', 'format' => fn ($v) => '<span class="badge '.($v === 'return' ? 'badge-warn' : 'badge-primary').'">'.e($types[$v] ?? $v).'</span>'])
            ->column('supplier_name', __('Təchizatçı'), 'text', ['sql' => 's.name', 'link' => fn ($r) => route('suppliers.show', $r->supplier_id)])
            ->column('supplier_invoice_no', __('Təchizatçı qaiməsi'), 'text', ['sql' => 'd.supplier_invoice_no', 'mobile' => false])
            ->column('warehouse_name', __('Anbar'), 'text', ['sql' => 'w.name', 'mobile' => false])
            ->column('total_amount', __('Məbləğ (valyuta)'), 'text', ['sql' => 'd.total_amount', 'format' => fn ($v, $r) => e(Fmt::number($v, 2).' '.$r->currency)])
            ->column('total_cost_azn', __('Stok dəyəri (AZN)'), 'money', ['sql' => 'd.total_cost_azn'])
            ->column('due_date', __('Ödəniş tarixi'), 'date', ['sql' => 'd.due_date', 'mobile' => false])
            ->source($query)->sortBy('date', 'desc');
        if ($response = $this->exportIfRequested($request, [$table], __('Alış sənədləri'), $filters)) {
            return $response;
        }

        return view('purchases.index', ['title' => $type ? $types[$type] : __('Alış sənədləri'), 'filters' => $filters, 'table' => $table,
            'rows' => $table->resolve($request), 'types' => $types, 'type' => $type]);
    }

    public function create(Request $request): View
    {
        $type = $request->query('type') === 'return' ? 'return' : 'receipt';
        $original = $type === 'return' && is_numeric($request->query('from')) ? PurchaseDocument::query()->active()->where('type', 'receipt')->find((int) $request->query('from')) : null;
        $lastCost = DB::table('purchase_document_lines as l')->join('purchase_documents as d', 'd.id', '=', 'l.purchase_document_id')->whereNull('d.cancelled_at')
            ->orderBy('d.date')->orderBy('l.id')->pluck('l.price', 'l.product_id');
        $products = DB::table('products')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'barcode', 'vat_mode', 'vat_rate'])
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'barcode' => $p->barcode, 'vat' => Vat::rate($p->vat_mode, $p->vat_rate),
                'last' => isset($lastCost[$p->id]) ? (float) $lastCost[$p->id] : null])->values();
        // daxil olan e-qaimədən: təchizatçı, seriya, tarix və uyğunlaşdırılmış sətirlər (qiymət ƏDV-siz)
        $einvoice = $type === 'receipt' && is_numeric($request->query('einvoice')) ? IncomingEInvoice::query()->find((int) $request->query('einvoice')) : null;
        $lines = old('lines', $einvoice ? app(EInvoiceService::class)->purchaseLines($einvoice)->all() : ($original ? DB::table('purchase_document_lines')->where('purchase_document_id', $original->id)->orderBy('row_no')
            ->get(['product_id', 'qty', 'price'])->map(fn ($l) => ['product_id' => $l->product_id, 'qty' => (float) $l->qty, 'price' => (float) $l->price])->all() : []));
        $suppliers = Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'currency', 'payment_days']);
        $warehouses = Warehouse::query()->where('is_active', true)->where('type', '<>', 'transit')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']);

        return view('purchases.form', [
            'title' => PurchaseDocument::typeLabels()[$type], 'type' => $type, 'original' => $original, 'warehouses' => $warehouses, 'einvoice' => $einvoice,
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'config' => [
                'type' => $type, 'products' => $products, 'suppliers' => $suppliers, 'lines' => $lines,
                'supplier' => (int) old('supplier_id', $original->supplier_id ?? $einvoice->supplier_id ?? $request->integer('supplier')) ?: null,
                'rate' => old('rate', $original?->rate), 'vatIncluded' => (bool) old('vat_included', $original->vat_included ?? false),
                'costs' => ['customs' => old('customs_cost', ''), 'transport' => old('transport_cost', ''), 'other' => old('other_cost', '')],
                'date' => old('date', $einvoice?->issued_at && $einvoice->issued_at->lte(now()) ? $einvoice->issued_at->toDateString() : now()->toDateString()), 'rateUrl' => route('purchase-docs.rate'),
            ],
            'values' => ['warehouse_id' => (int) old('warehouse_id', $original->warehouse_id ?? $warehouses->first()?->id), 'workplace_id' => (int) old('workplace_id', Workplace::defaultIdFor($request->user()))],
        ]);
    }

    public function store(Request $request, PurchaseService $service): RedirectResponse
    {
        $type = $request->input('type') === 'return' ? 'return' : 'receipt';
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
            'original_document_id' => ['nullable', 'integer', 'exists:purchase_documents,id'],
            'incoming_einvoice_id' => ['nullable', 'integer', 'exists:incoming_einvoices,id'],
            'rate' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'vat_included' => ['nullable', 'boolean'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],
            'supplier_invoice_date' => ['nullable', 'date'],
            'customs_cost' => ['nullable', 'numeric', 'min:0'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'other_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'lines.*.price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ], [], ['supplier_id' => __('Təchizatçı'), 'lines' => __('Məhsullar'), 'rate' => __('Məzənnə')]);
        $data['workplace_id'] ??= Workplace::defaultIdFor($request->user());
        try {
            $doc = $service->create($type, $data, array_values($data['lines']), $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['rate' => $e->getMessage()]);
        }
        if (! empty($data['incoming_einvoice_id'])) {
            IncomingEInvoice::query()->whereKey($data['incoming_einvoice_id'])->update(['purchase_document_id' => $doc->id]);
        }
        $this->rebuild();

        return redirect()->route('purchase-docs.show', $doc)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => PurchaseDocument::typeLabels()[$type], 'no' => $doc->doc_no, 'url' => route('purchase-docs.show', $doc)]]]);
    }

    public function show(Request $request, PurchaseDocument $purchaseDocument): View
    {
        $doc = $purchaseDocument->load('supplier', 'warehouse', 'workplace', 'creator', 'canceller', 'original');

        return view($request->boolean('print') ? 'purchases.print' : 'purchases.show', [
            'title' => PurchaseDocument::typeLabels()[$doc->type].' № '.$doc->doc_no, 'doc' => $doc,
            'lines' => DB::table('purchase_document_lines')->where('purchase_document_id', $doc->id)->orderBy('row_no')->get(),
            'returns' => PurchaseDocument::query()->where('original_document_id', $doc->id)->orderBy('id')->get(['id', 'doc_no', 'cancelled_at']),
        ]);
    }

    public function destroy(Request $request, PurchaseDocument $purchaseDocument, PurchaseService $service): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        try {
            $service->cancel($purchaseDocument, $request->user()->id, $reason);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->rebuild();

        return redirect()->route('purchase-docs.show', $purchaseDocument)->with('popup', ['title' => __('Sənəd ləğv edildi'),
            'message' => __(':type № :no ləğv edildi, stok və təchizatçı borcu düzəldildi.', ['type' => PurchaseDocument::typeLabels()[$purchaseDocument->type], 'no' => $purchaseDocument->doc_no])]);
    }

    /**
     * Mərkəzi Bank məzənnəsi (forma üçün).
     */
    public function rate(Request $request, CurrencyRates $rates): JsonResponse
    {
        $data = $request->validate(['code' => ['required', Rule::in(CurrencyRates::codes())], 'date' => ['required', 'date']]);

        return response()->json(['rate' => $rates->rate($data['code'], $data['date'])]);
    }

    private function rebuild(): void
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }
}
