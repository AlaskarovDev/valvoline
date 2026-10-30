<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Models\PurchaseDocument;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\Workplace;
use App\Reports\Table;
use App\Services\PurchaseOrderService;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Alış sifarişləri (AS-) və satınalma təklifi.
 */
class PurchaseOrderController extends ReportController
{
    public function index(Request $request, PurchaseOrderService $orders): View|Response
    {
        $filters = $this->filters($request, 'this_year');
        $query = $this->applyState($request, DB::table('purchase_orders as o'), 'o')->join('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->whereBetween('o.date', [$filters->fromDate(), $filters->toDate()])
            ->select('o.id', 'o.date', 'o.doc_no', 'o.expected_date', 'o.currency', 'o.total_amount', 'o.closed_at', 'o.cancelled_at', 's.id as supplier_id', 's.name as supplier_name');
        $labels = PurchaseOrder::statusLabels();
        $table = Table::make('pos', __('Alış sifarişləri'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'o.date'])
            ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 'o.doc_no', 'link' => fn ($r) => route('purchase-orders.show', $r->id)])
            ->column('supplier_name', __('Təchizatçı'), 'text', ['sql' => 's.name', 'link' => fn ($r) => route('suppliers.show', $r->supplier_id)])
            ->column('expected_date', __('Gözlənilən tarix'), 'date', ['sql' => 'o.expected_date', 'class' => fn ($v, $r) => $v && substr((string) $v, 0, 10) < now()->toDateString() && ! $r->closed_at && ! $r->cancelled_at ? 'warn' : ''])
            ->column('total_amount', __('Məbləğ'), 'text', ['sql' => 'o.total_amount', 'format' => fn ($v, $r) => e(Fmt::number($v, 2).' '.$r->currency)])
            ->column('status', __('Vəziyyət'), 'text', ['sortable' => false, 'format' => function ($v, $r) use ($orders, $labels) {
                $status = $orders->status(PurchaseOrder::query()->find($r->id));

                return '<span class="badge '.match ($status) {
                    'received' => 'badge-pos', 'partial' => 'badge-warn', 'cancelled' => 'badge-neg', default => ''
                }.'">'.e($labels[$status]).'</span>';
            }])
            ->source($query)->sortBy('date', 'desc');
        if ($response = $this->exportIfRequested($request, [$table], __('Alış sifarişləri'), $filters)) {
            return $response;
        }

        return view('purchase-orders.index', ['title' => __('Alış sifarişləri'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request)]);
    }

    /**
     * Satınalma təklifi: təchizatçı seçilir, mallar üzrə təklif olunan miqdar redaktə edilib sifarişə çevrilir.
     */
    public function suggest(Request $request, PurchaseOrderService $orders): View
    {
        $suppliers = Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'currency', 'lead_days']);
        $supplier = $suppliers->firstWhere('id', $request->integer('supplier')) ?? null;
        $cover = max(1, min(365, $request->integer('cover', PurchaseOrderService::DEFAULT_COVER_DAYS)));
        $all = $request->boolean('all');

        return view('purchase-orders.suggest', [
            'title' => __('Satınalma təklifi'), 'suppliers' => $suppliers, 'supplier' => $supplier, 'cover' => $cover, 'all' => $all,
            'rows' => $orders->suggestions($supplier, $cover, $all),
            'lead' => (int) ($supplier?->lead_days ?? PurchaseOrderService::DEFAULT_LEAD_DAYS),
            'warehouses' => Warehouse::query()->where('is_active', true)->where('type', '<>', 'transit')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, PurchaseOrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'max:1000'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ], [], ['supplier_id' => __('Təchizatçı'), 'lines' => __('Məhsullar')]);
        try {
            $order = $orders->create($data + ['workplace_id' => Workplace::defaultIdFor($request->user())],
                array_map(fn ($l) => $l + ['qty' => $l['qty'] ?? 0], array_values($data['lines'])), $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('purchase-orders.show', $order)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => __('Alış sifarişi'), 'no' => $order->doc_no, 'url' => route('purchase-orders.show', $order)]]]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $orders): View
    {
        $order = $purchaseOrder->load('supplier', 'warehouse', 'creator', 'canceller');
        $progress = $orders->progress([$order->id])[$order->id] ?? [];

        return view($request->boolean('print') ? 'purchase-orders.print' : 'purchase-orders.show', [
            'title' => __('Alış sifarişi').' № '.$order->doc_no, 'doc' => $order, 'status' => $orders->status($order, $progress), 'progress' => $progress,
            'lines' => DB::table('purchase_order_lines')->where('purchase_order_id', $order->id)->orderBy('row_no')->get(),
            'receipts' => PurchaseDocument::query()->where('purchase_order_id', $order->id)->orderBy('id')->get(['id', 'doc_no', 'date', 'cancelled_at']),
        ]);
    }

    /**
     * Qalığı artıq gözlənilmir — sifariş bağlanır (yoldakı miqdar təklifdən çıxır).
     */
    public function close(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $purchaseOrder->assertNotCancelled();
        $purchaseOrder->forceFill(['closed_at' => $purchaseOrder->closed_at ? null : now()])->save();

        return back()->with('status', $purchaseOrder->closed_at ? __('Sifariş bağlandı — qalıq artıq gözlənilmir.') : __('Sifariş yenidən açıldı.'));
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        if (PurchaseDocument::query()->active()->where('purchase_order_id', $purchaseOrder->id)->exists()) {
            return back()->with('error', __('Sifarişə bağlı mədaxil var — əvvəlcə mədaxili ləğv edin və ya sifarişi bağlayın.'));
        }
        $purchaseOrder->markCancelled($request->user()->id, $reason);

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('popup', ['title' => __('Sənəd ləğv edildi'),
            'message' => __(':type № :no ləğv edildi.', ['type' => __('Alış sifarişi'), 'no' => $purchaseOrder->doc_no])]);
    }
}
