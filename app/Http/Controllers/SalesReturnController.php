<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Jobs\RebuildAnalytics;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesReturnDocument;
use App\Models\Warehouse;
use App\Models\Workplace;
use App\Reports\Table;
use App\Services\AnalyticsRebuilder;
use App\Services\SalesReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satış → Qaytarma qaimələri (müştəridən mal qaytarılması, kredit-qeyd).
 */
class SalesReturnController extends ReportController
{
    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_year');
        $restricted = $request->user()->restrictedManagerId();
        $reasons = SalesReturnDocument::reasons();
        $query = $this->applyState($request, DB::table('sales_return_documents as d'), 'd')
            ->join('customers as c', 'c.id', '=', 'd.customer_id')->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('sales_invoices as i', 'i.id', '=', 'd.sales_invoice_id')
            ->when($restricted !== null, fn ($q) => $q->where('d.manager_id', $restricted))
            ->whereBetween('d.date', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->customerId, fn ($q) => $q->where('d.customer_id', $filters->customerId))
            ->select('d.id', 'd.doc_no', 'd.date', 'd.reason', 'd.total_amount', 'd.vat_amount', 'd.customer_id', 'c.name as customer_name', 'w.name as warehouse_name', 'i.id as invoice_id', 'i.doc_no as invoice_no');
        $table = Table::make('salesreturns', __('Qaytarma qaimələri'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'd.date'])
            ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 'd.doc_no', 'link' => fn ($r) => route('sales-returns.show', $r->id)])
            ->column('customer_name', __('Müştəri'), 'text', ['sql' => 'c.name', 'link' => fn ($r) => route('customers.show', $r->customer_id)])
            ->column('invoice_no', __('Faktura'), 'text', ['sql' => 'i.doc_no', 'link' => fn ($r) => $r->invoice_id ? route('invoices.show', $r->invoice_id) : null])
            ->column('reason', __('Səbəb'), 'text', ['sql' => 'd.reason', 'format' => fn ($v) => e($reasons[$v] ?? $v)])
            ->column('warehouse_name', __('Anbar'), 'text', ['sql' => 'w.name', 'mobile' => false])
            ->column('vat_amount', __('ƏDV'), 'money', ['sql' => 'd.vat_amount', 'mobile' => false])
            ->column('total_amount', __('Məbləğ'), 'money', ['sql' => 'd.total_amount', 'class' => fn () => 'font-semibold'])
            ->source($query)->sortBy('date', 'desc')->footer(auto: true)
            ->subtitle(__('Qaytarma müştəri borcunu azaldır, mal seçilmiş anbara qayıdır.'));
        if ($response = $this->exportIfRequested($request, [$table], __('Qaytarma qaimələri'), $filters)) {
            return $response;
        }

        return view('salesreturns.index', ['title' => __('Qaytarma qaimələri'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request)]);
    }

    public function create(Request $request, SalesReturnService $service): View
    {
        $restricted = $request->user()->restrictedManagerId();
        $invoice = is_numeric($request->query('invoice')) ? SalesInvoice::query()->active()->find((int) $request->query('invoice')) : null;
        abort_if($invoice && $restricted !== null && $invoice->manager_id !== $restricted, 403);
        $warehouses = Warehouse::query()->where('is_active', true)->where('type', '<>', 'transit')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'type']);

        return view('salesreturns.form', [
            'title' => __('Qaytarma qaiməsi'), 'invoice' => $invoice?->load('customer'), 'warehouses' => $warehouses,
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'reasons' => SalesReturnDocument::reasons(),
            'config' => [
                'invoiceLines' => $invoice ? $service->invoiceLines($invoice) : [],
                'customers' => $invoice ? [] : Customer::query()->when($restricted !== null, fn ($q) => $q->where('manager_id', $restricted))->orderBy('name')->get(['id', 'code', 'name'])
                    ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values(),
                'products' => $invoice ? [] : DB::table('products')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'barcode', 'sale_price'])
                    ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'barcode' => $p->barcode, 'price' => (float) $p->sale_price])->values(),
                'customer' => (int) old('customer_id', $request->integer('customer')) ?: null,
            ],
            'values' => ['warehouse_id' => (int) old('warehouse_id', $invoice->warehouse_id ?? $warehouses->first()?->id), 'workplace_id' => (int) old('workplace_id', $invoice->workplace_id ?? Workplace::defaultIdFor($request->user()))],
        ]);
    }

    public function store(Request $request, SalesReturnService $service): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'sales_invoice_id' => ['nullable', 'integer', 'exists:sales_invoices,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
            'reason' => ['required', Rule::in(array_keys(SalesReturnDocument::reasons()))],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.sales_line_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ], [], ['customer_id' => __('Müştəri'), 'lines' => __('Məhsullar')]);
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && Customer::query()->whereKey($data['customer_id'])->value('manager_id') !== $restricted, 403);
        $data['workplace_id'] ??= Workplace::defaultIdFor($request->user());
        try {
            $doc = $service->create($data, array_values($data['lines']), $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }
        $this->rebuild();

        return redirect()->route('sales-returns.show', $doc)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => __('Müştəri borcu :amount azaldı.', ['amount' => number_format((float) $doc->total_amount, 2, ',', ' ').' ₼']),
            'docs' => [['type' => __('Qaytarma qaiməsi'), 'no' => $doc->doc_no, 'url' => route('sales-returns.show', $doc)]]]);
    }

    public function show(Request $request, SalesReturnDocument $salesReturn): View
    {
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $salesReturn->manager_id !== $restricted, 403);

        return view($request->boolean('print') ? 'salesreturns.print' : 'salesreturns.show', [
            'title' => __('Qaytarma qaiməsi').' № '.$salesReturn->doc_no, 'doc' => $salesReturn->load('customer', 'manager', 'warehouse', 'workplace', 'invoice', 'creator', 'canceller'),
            'lines' => DB::table('sales_return_document_lines')->where('sales_return_document_id', $salesReturn->id)->orderBy('row_no')->get(),
            'reasons' => SalesReturnDocument::reasons(),
        ]);
    }

    public function destroy(Request $request, SalesReturnDocument $salesReturn, SalesReturnService $service): RedirectResponse
    {
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $salesReturn->manager_id !== $restricted, 403);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $service->cancel($salesReturn, $request->user()->id, $reason);
        $this->rebuild();

        return redirect()->route('sales-returns.show', $salesReturn)->with('popup', ['title' => __('Sənəd ləğv edildi'), 'message' => __(':type № :no ləğv edildi, müştəri borcu bərpa olundu.', ['type' => __('Qaytarma qaiməsi'), 'no' => $salesReturn->doc_no])]);
    }

    private function rebuild(): void
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }
}
