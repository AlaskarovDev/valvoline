<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Jobs\RebuildAnalytics;
use App\Models\StockDocument;
use App\Models\Warehouse;
use App\Models\Workplace;
use App\Reports\Table;
use App\Services\AnalyticsRebuilder;
use App\Services\StockDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Məhsul idarəetməsi → Sənədlər: açılış, sayım artığı / əskiyi, istehsal itkisi, sərf.
 */
class StockDocumentController extends ReportController
{
    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'all');
        $types = StockDocument::typeLabels();
        $type = array_key_exists((string) $request->query('type'), $types) ? $request->query('type') : null;
        $query = $this->applyState($request, DB::table('stock_documents as d'), 'd')->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')->leftJoin('workplaces as wp', 'wp.id', '=', 'd.workplace_id')
            ->whereBetween('d.date', [$filters->fromDate(), $filters->toDate()])
            ->when($type, fn ($q) => $q->where('d.type', $type))
            ->select('d.id', 'd.doc_no', 'd.type', 'd.date', 'd.total_qty', 'd.total_cost', 'd.purpose', 'd.note', 'w.name as warehouse_name', 'wp.name as workplace_name');
        $table = Table::make('stockdocs', $type ? $types[$type] : __('Stok sənədləri'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'd.date'])
            ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 'd.doc_no', 'link' => fn ($r) => route('stock-docs.show', $r->id)])
            ->column('type', __('Sənəd növü'), 'text', ['sql' => 'd.type', 'format' => fn ($v) => e($types[$v] ?? $v)])
            ->column('warehouse_name', __('Anbar'), 'text', ['sql' => 'w.name'])
            ->column('workplace_name', __('İş yeri'), 'text', ['sql' => 'wp.name', 'mobile' => false])
            ->column('total_qty', __('Miqdar'), 'qty', ['sql' => 'd.total_qty'])
            ->column('total_cost', __('Dəyər (maya)'), 'money', ['sql' => 'd.total_cost'])
            ->column('purpose', __('Məqsəd'), 'text', ['sql' => 'd.purpose', 'mobile' => false])
            ->column('note', __('Qeyd'), 'text', ['sql' => 'd.note', 'mobile' => false])
            ->source($query)->sortBy('date', 'desc')->footer(auto: true);
        if ($response = $this->exportIfRequested($request, [$table], $type ? $types[$type] : __('Stok sənədləri'), $filters)) {
            return $response;
        }

        return view('stockdocs.index', [
            'title' => $type ? $types[$type] : __('Stok sənədləri'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request),
            'types' => $types, 'type' => $type, 'help' => StockDocument::typeHelp(),
        ]);
    }

    public function create(Request $request): View
    {
        $types = StockDocument::typeLabels();
        $type = array_key_exists((string) $request->query('type'), $types) ? $request->query('type') : 'count_surplus';
        $stock = [];
        foreach (DB::table('stock_balances')->get(['product_id', 'warehouse_id', 'qty']) as $r) {
            $stock[$r->product_id][$r->warehouse_id] = round((float) $r->qty, 2);
        }
        $units = DB::table('product_units as pu')->join('units as u', 'u.id', '=', 'pu.unit_id')->get(['pu.product_id', 'pu.factor', 'pu.is_sale_default', 'u.name'])->groupBy('product_id');
        $baseUnits = DB::table('products as p')->join('units as u', 'u.id', '=', 'p.base_unit_id')->pluck('u.name', 'p.id');
        $lastCost = DB::table('purchase_lines')->whereNotNull('product_id')->where('unit_cost', '>', 0)->orderBy('date')->orderBy('id')->pluck('unit_cost', 'product_id');
        $products = DB::table('products')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'barcode', 'current_avg_cost'])
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'barcode' => $p->barcode, 'stock' => (object) ($stock[$p->id] ?? []),
                'cost' => round((float) ($p->current_avg_cost ?? $lastCost[$p->id] ?? 0), 4), 'base_unit' => $baseUnits[$p->id] ?? '',
                'units' => ($units[$p->id] ?? collect())->map(fn ($u) => ['name' => $u->name, 'factor' => (float) $u->factor])->values()])->values();
        $warehouses = Warehouse::query()->where('is_active', true)->where('type', '<>', 'transit')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']);

        return view('stockdocs.form', [
            'title' => $types[$type], 'type' => $type, 'types' => $types, 'help' => StockDocument::typeHelp(),
            'warehouses' => $warehouses,
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'config' => ['products' => $products, 'type' => $type, 'isCount' => StockDocument::isCount($type),
                'warehouse' => (int) old('warehouse_id', $warehouses->first()?->id), 'lines' => old('lines', [])],
            'workplaceId' => (int) old('workplace_id', Workplace::defaultIdFor($request->user())),
        ]);
    }

    public function store(Request $request, StockDocumentService $service): RedirectResponse
    {
        $type = (string) $request->input('type');
        abort_unless(array_key_exists($type, StockDocument::TYPES), 422);
        $data = $request->validate([
            'type' => ['required'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'lines.*.counted_qty' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'lines.*.unit_cost' => [$type === 'opening' ? 'required' : 'nullable', 'numeric', 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ], [], ['lines' => __('Məhsullar'), 'lines.*.unit_cost' => __('Vahid maya')]);
        $data['workplace_id'] ??= Workplace::defaultIdFor($request->user());
        try {
            $doc = $service->create($type, $data, array_values($data['lines']), $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }
        $this->rebuild();

        return redirect()->route('stock-docs.show', $doc)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => StockDocument::typeLabels()[$type], 'no' => $doc->doc_no, 'url' => route('stock-docs.show', $doc)]]]);
    }

    public function show(Request $request, StockDocument $stockDocument): View
    {
        $doc = $stockDocument->load('warehouse', 'workplace', 'creator', 'canceller');
        $lines = DB::table('stock_document_lines')->where('stock_document_id', $doc->id)->orderBy('row_no')->get();
        // sənəddən sonrakı hesablamanın mayası (artıq / əskik / itki / sərf üçün)
        $costs = DB::table('stock_movements as m')->join('stock_adjustments as a', 'a.id', '=', 'm.source_id')
            ->where('m.source_type', 'stock_adjustment')->where('a.stock_document_id', $doc->id)->pluck('m.unit_cost', 'a.product_id');
        $lines->each(function ($l) use ($costs, $doc) {
            $l->cost = $doc->type === 'opening' ? (float) $l->unit_cost : (float) ($costs[$l->product_id] ?? $l->unit_cost ?? 0);
        });

        return view($request->boolean('print') ? 'stockdocs.print' : 'stockdocs.show', [
            'title' => StockDocument::typeLabels()[$doc->type].' № '.$doc->doc_no, 'doc' => $doc, 'lines' => $lines,
            'types' => StockDocument::typeLabels(), 'sign' => StockDocument::TYPES[$doc->type][1],
        ]);
    }

    public function destroy(Request $request, StockDocument $stockDocument, StockDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stock.manage'), 403);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $no = $stockDocument->doc_no;
        $type = StockDocument::typeLabels()[$stockDocument->type];
        $service->cancel($stockDocument, $request->user()->id, $reason);
        $this->rebuild();

        return redirect()->route('stock-docs.show', $stockDocument)->with('popup', ['title' => __('Sənəd ləğv edildi'),
            'message' => __(':type № :no ləğv edildi, stok əvvəlki vəziyyətə qaytarıldı.', ['type' => $type, 'no' => $no]), 'docs' => []]);
    }

    private function rebuild(): void
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }
}
