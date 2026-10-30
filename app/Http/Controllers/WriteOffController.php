<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Jobs\RebuildAnalytics;
use App\Models\StockWriteOff;
use App\Models\Warehouse;
use App\Models\Workplace;
use App\Reports\Table;
use App\Services\AnalyticsRebuilder;
use App\Services\StockWriteOffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Silinmə aktları: vaxtı bitmiş, zədələnmiş, kəsir — siyahı, əl ilə yaratma, çap və ləğv.
 */
class WriteOffController extends ReportController
{
    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'last_12');
        $reasons = StockWriteOffService::reasons();
        $query = $this->applyState($request, DB::table('stock_write_offs as d'), 'd')->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->whereBetween('d.date', [$filters->fromDate(), $filters->toDate()])
            ->select('d.id', 'd.doc_no', 'd.date', 'd.reason', 'd.total_qty', 'd.total_cost', 'd.note', 'w.name as warehouse_name');
        $table = Table::make('writeoffs', __('Silinmə aktları'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'd.date'])
            ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 'd.doc_no', 'link' => fn ($r) => route('write-offs.show', $r->id)])
            ->column('warehouse_name', __('Anbar'), 'text', ['sql' => 'w.name'])
            ->column('reason', __('Səbəb'), 'text', ['sql' => 'd.reason', 'format' => fn ($v) => e($reasons[$v] ?? $v)])
            ->column('total_qty', __('Miqdar'), 'qty', ['sql' => 'd.total_qty'])
            ->column('total_cost', __('Dəyər (maya)'), 'money', ['sql' => 'd.total_cost'])
            ->column('note', __('Qeyd'), 'text', ['sql' => 'd.note', 'mobile' => false])
            ->source($query)->sortBy('date', 'desc')->footer(auto: true)
            ->subtitle(__('Silinmə real stoku azaldır, dəyəri maya ilə itki kimi uçota düşür.'));
        if ($response = $this->exportIfRequested($request, [$table], __('Silinmə aktları'), $filters)) {
            return $response;
        }

        return view('writeoffs.index', [
            'title' => __('Silinmə aktları'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request), 'reasons' => $reasons,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'workplaceId' => Workplace::defaultIdFor($request->user()),
            'products' => DB::table('products')->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name])->values(),
        ]);
    }

    public function store(Request $request, StockWriteOffService $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reason' => ['required', Rule::in(array_keys(StockWriteOffService::reasons()))],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);
        $doc = $service->create((int) $data['warehouse_id'], Carbon::parse($data['date']), $data['reason'],
            array_map(fn ($l) => ['product_id' => (int) $l['product_id'], 'qty' => (float) $l['qty']], $data['lines']), $request->user()->id, $data['note'] ?? null, null, isset($data['workplace_id']) ? (int) $data['workplace_id'] : null);
        $this->rebuild();

        return redirect()->route('write-offs.show', $doc)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => __('Silinmə aktı'), 'no' => $doc->doc_no, 'url' => route('write-offs.show', $doc)]]]);
    }

    public function show(Request $request, StockWriteOff $writeOff): View
    {
        $lines = DB::table('stock_write_off_lines as l')->leftJoin('product_batches as b', 'b.id', '=', 'l.product_batch_id')
            ->where('l.stock_write_off_id', $writeOff->id)->orderBy('l.id')
            ->get(['l.*', 'b.batch_no', 'b.expiry_date', 'b.production_date']);

        return view($request->boolean('print') ? 'writeoffs.print' : 'writeoffs.show', [
            'title' => __('Silinmə aktı').' № '.$writeOff->doc_no, 'doc' => $writeOff->load('warehouse', 'creator', 'workplace'), 'lines' => $lines,
            'reasons' => StockWriteOffService::reasons(),
            'transfer' => $writeOff->warehouse_transfer_id ? DB::table('warehouse_transfers')->find($writeOff->warehouse_transfer_id) : null,
        ]);
    }

    public function destroy(Request $request, StockWriteOff $writeOff, StockWriteOffService $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $no = $writeOff->doc_no;
        $service->cancel($writeOff, $request->user()->id, $reason);
        $this->rebuild();

        return redirect()->route('write-offs.show', $writeOff)->with('popup', ['title' => __('Sənəd ləğv edildi'), 'message' => __(':type № :no ləğv edildi, mal stoka qaytarıldı.', ['type' => __('Silinmə aktı'), 'no' => $no]), 'docs' => []]);
    }

    private function rebuild(): void
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }
}
