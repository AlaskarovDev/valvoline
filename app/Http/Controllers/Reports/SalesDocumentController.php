<?php

namespace App\Http\Controllers\Reports;

use App\Jobs\RebuildAnalytics;
use App\Models\DeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Reports\Table;
use App\Services\AnalyticsRebuilder;
use App\Services\SalesDocumentService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Təhvil-təslim sənədləri və satış fakturaları: siyahı, detal, çap şablonu, ləğv.
 */
class SalesDocumentController extends ReportController
{
    public function deliveries(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_month');
        $query = $this->scoped($request, DB::table('delivery_notes as d'), 'd')
            ->leftJoin('sales_orders as o', 'o.id', '=', 'd.sales_order_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('sales_invoices as i', 'i.delivery_note_id', '=', 'd.id')
            ->whereBetween('d.date', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->customerId, fn ($q) => $q->where('d.customer_id', $filters->customerId))
            ->select('d.id', 'd.date', 'd.doc_no', 'd.customer_id', 'd.customer_name', 'd.total_qty', 'd.total_amount', 'd.total_cogs',
                'o.id as order_id', 'o.order_no', 'w.name as warehouse_name', 'i.id as invoice_id', 'i.doc_no as invoice_no');

        $table = Table::make('deliveries', __('Təhvil-təslim sənədləri'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'd.date'])
            ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 'd.doc_no', 'link' => fn ($r) => route('deliveries.show', $r->id)])
            ->column('order_no', __('Sifariş №'), 'text', ['sql' => 'o.order_no', 'link' => fn ($r) => $r->order_id ? route('orders.show', $r->order_id) : null])
            ->column('customer_name', __('Müştəri'), 'text', ['sql' => 'd.customer_name', 'link' => fn ($r) => $r->customer_id ? route('customers.show', $r->customer_id) : null])
            ->column('warehouse_name', __('Anbar'), 'text', ['sql' => 'w.name', 'mobile' => false])
            ->column('total_qty', __('Miqdar'), 'qty', ['sql' => 'd.total_qty'])
            ->column('total_amount', __('Məbləğ'), 'money', ['sql' => 'd.total_amount'])
            ->column('total_cogs', __('Maya'), 'money', ['sql' => 'd.total_cogs', 'mobile' => false])
            ->column('invoice_no', __('Faktura'), 'text', ['sql' => 'i.doc_no', 'link' => fn ($r) => $r->invoice_id ? route('invoices.show', $r->invoice_id) : null,
                'format' => fn ($v) => $v ? e($v) : '<span class="badge badge-warn">'.e(__('fakturalanmayıb')).'</span>'])
            ->source($query)->sortBy('date', 'desc')->footer(auto: true)
            ->subtitle(__('Təhvil-təslim real stoku azaldır.'));

        if ($response = $this->exportIfRequested($request, [$table], __('Təhvil-təslim sənədləri'), $filters)) {
            return $response;
        }

        return view('documents.index', ['title' => __('Təhvil-təslim sənədləri'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request)]);
    }

    public function invoices(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_month');
        $query = $this->scoped($request, DB::table('sales_invoices as i'), 'i')
            ->leftJoin('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->leftJoin('delivery_notes as d', 'd.id', '=', 'i.delivery_note_id')
            ->leftJoin('managers as m', 'm.id', '=', 'i.manager_id')
            ->whereBetween('i.date', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->customerId, fn ($q) => $q->where('i.customer_id', $filters->customerId))
            ->when($filters->managerId, fn ($q) => $q->where('i.manager_id', $filters->managerId))
            ->select('i.id', 'i.date', 'i.doc_no', 'i.customer_id', 'i.customer_name', 'i.gross_amount', 'i.discount_amount', 'i.total_amount',
                'o.id as order_id', 'o.order_no', 'd.id as delivery_id', 'd.doc_no as delivery_no', 'm.name as manager_name');

        $table = Table::make('invoices', __('Satış fakturaları'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'i.date'])
            ->column('doc_no', __('Faktura №'), 'text', ['sql' => 'i.doc_no', 'link' => fn ($r) => route('invoices.show', $r->id)])
            ->column('order_no', __('Sifariş №'), 'text', ['sql' => 'o.order_no', 'link' => fn ($r) => $r->order_id ? route('orders.show', $r->order_id) : null])
            ->column('delivery_no', __('Təhvil-təslim'), 'text', ['sql' => 'd.doc_no', 'link' => fn ($r) => $r->delivery_id ? route('deliveries.show', $r->delivery_id) : null, 'mobile' => false])
            ->column('customer_name', __('Müştəri'), 'text', ['sql' => 'i.customer_name', 'link' => fn ($r) => $r->customer_id ? route('customers.show', $r->customer_id) : null])
            ->column('manager_name', __('Menecer'), 'text', ['sql' => 'm.name', 'mobile' => false])
            ->column('gross_amount', __('Məbləğ (endirimsiz)'), 'money', ['sql' => 'i.gross_amount', 'mobile' => false])
            ->column('discount_amount', __('Endirim'), 'money', ['sql' => 'i.discount_amount', 'mobile' => false])
            ->column('total_amount', __('Yekun'), 'money', ['sql' => 'i.total_amount', 'class' => fn () => 'font-semibold'])
            ->source($query)->sortBy('date', 'desc')->footer(auto: true)
            ->subtitle(__('Faktura müştəri borcuna və satış gəlirinə yazılır.'));

        if ($response = $this->exportIfRequested($request, [$table], __('Satış fakturaları'), $filters)) {
            return $response;
        }

        return view('documents.index', ['title' => __('Satış fakturaları'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request)]);
    }

    public function delivery(Request $request, DeliveryNote $delivery): View
    {
        $this->authorizeDoc($request, $delivery->manager_id);
        $lines = DB::table('delivery_note_lines')->where('delivery_note_id', $delivery->id)->orderBy('row_no')->get();
        $invoice = SalesInvoice::query()->where('delivery_note_id', $delivery->id)->first();

        return view($request->boolean('print') ? 'documents.print' : 'documents.show', [
            'title' => __('Təhvil-təslim sənədi').' № '.$delivery->doc_no,
            'kind' => 'delivery', 'doc' => $delivery->load('customer', 'manager', 'warehouse', 'order', 'creator'), 'lines' => $lines,
            'campaigns' => $this->campaignsByItem($lines->pluck('sales_order_item_id')),
            'related' => array_filter([
                $delivery->order ? [__('Sifariş'), $delivery->order->order_no, route('orders.show', $delivery->order)] : null,
                $invoice ? [__('Satış fakturası'), $invoice->doc_no, route('invoices.show', $invoice)] : null,
            ]),
            'canCancel' => $invoice === null,
            'totals' => ['qty' => $lines->sum('qty'), 'amount' => $lines->where('op_type', 'sale')->sum('net_amount'), 'cogs' => $lines->sum('cogs')],
        ]);
    }

    public function invoice(Request $request, SalesInvoice $invoice): View
    {
        $this->authorizeDoc($request, $invoice->manager_id);
        $lines = DB::table('sales_lines as l')->leftJoin('delivery_note_lines as d', 'd.id', '=', 'l.delivery_note_line_id')
            ->where('l.sales_invoice_id', $invoice->id)->orderBy('l.row_no')
            ->get(['l.*', 'd.sales_order_item_id']);

        return view($request->boolean('print') ? 'documents.print' : 'documents.show', [
            'title' => __('Satış fakturası').' № '.$invoice->doc_no,
            'kind' => 'invoice', 'doc' => $invoice->load('customer', 'manager', 'warehouse', 'order', 'deliveryNote', 'creator'), 'lines' => $lines,
            'campaigns' => $this->campaignsByItem($lines->pluck('sales_order_item_id')),
            'related' => array_filter([
                $invoice->order ? [__('Sifariş'), $invoice->order->order_no, route('orders.show', $invoice->order)] : null,
                $invoice->deliveryNote ? [__('Təhvil-təslim sənədi'), $invoice->deliveryNote->doc_no, route('deliveries.show', $invoice->deliveryNote)] : null,
            ]),
            'canCancel' => true,
            'totals' => ['qty' => $lines->sum('qty'), 'gross' => (float) $invoice->gross_amount, 'discount' => (float) $invoice->discount_amount, 'amount' => (float) $invoice->total_amount, 'cogs' => $lines->sum('cogs')],
        ]);
    }

    /**
     * Sifariş blankı (çap).
     */
    public function orderPrint(Request $request, SalesOrder $order): View
    {
        $this->authorizeDoc($request, $order->manager_id);
        abort_unless($order->source === 'manual', 404);
        $lines = DB::table('sales_order_items')->where('sales_order_id', $order->id)->orderBy('row_no')->get()
            ->map(fn ($l) => tap($l, fn ($x) => $x->sales_order_item_id = $l->id));
        $sale = $lines->where('op_type', 'sale');
        $order->doc_no = $order->order_no;

        return view('documents.print', [
            'title' => __('Sifariş').' № '.$order->order_no, 'kind' => 'order', 'doc' => $order->load('customer', 'manager', 'warehouse'), 'lines' => $lines,
            'campaigns' => $this->campaignsByItem($lines->pluck('id')),
            'totals' => ['qty' => $lines->sum('qty'), 'gross' => $sale->sum('amount'), 'discount' => $sale->sum('amount') - $sale->sum('net_amount'), 'amount' => $sale->sum('net_amount')],
        ]);
    }

    public function cancelDelivery(Request $request, DeliveryNote $delivery, SalesDocumentService $documents): RedirectResponse
    {
        $this->authorizeDoc($request, $delivery->manager_id);
        try {
            $documents->cancelDelivery($delivery);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->rebuild();

        return redirect()->route('deliveries.index')->with('popup', ['title' => __('Sənəd ləğv edildi'), 'message' => __(':type № :no ləğv edildi, mal anbara qaytarıldı və sifariş yenidən rezervdədir.', ['type' => __('Təhvil-təslim sənədi'), 'no' => $delivery->doc_no])]);
    }

    public function cancelInvoice(Request $request, SalesInvoice $invoice, SalesDocumentService $documents): RedirectResponse
    {
        $this->authorizeDoc($request, $invoice->manager_id);
        $documents->cancelInvoice($invoice);
        $this->rebuild();

        return redirect()->route('invoices.index')->with('popup', ['title' => __('Sənəd ləğv edildi'), 'message' => __(':type № :no ləğv edildi, müştəri borcundan çıxarıldı.', ['type' => __('Satış fakturası'), 'no' => $invoice->doc_no])]);
    }

    /**
     * @param  Collection<int, mixed>  $itemIds
     * @return Collection<int|string, Collection<int, object>>
     */
    private function campaignsByItem($itemIds)
    {
        return DB::table('sales_item_campaigns')->whereIn('sales_order_item_id', $itemIds->filter()->all() ?: [0])->orderBy('id')->get()->groupBy('sales_order_item_id');
    }

    private function scoped(Request $request, Builder $query, string $alias): Builder
    {
        $restricted = $request->user()->restrictedManagerId();

        return $query->when($restricted !== null, fn ($q) => $q->where($alias.'.manager_id', $restricted));
    }

    private function authorizeDoc(Request $request, ?int $managerId): void
    {
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $managerId !== $restricted, 403);
    }

    private function rebuild(): void
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }
}
