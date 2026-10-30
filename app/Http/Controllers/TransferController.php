<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Jobs\RebuildAnalytics;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Models\Workplace;
use App\Reports\Table;
use App\Services\AnalyticsRebuilder;
use App\Services\NumberingService;
use App\Services\SalesDocumentService;
use App\Services\WarehouseTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anbarlar arası transfer: qaralama → göndər (yolda) → qəbul et (qismən), kəsirin silinməsi, ləğv, çap.
 */
class TransferController extends ReportController
{
    public function __construct(private WarehouseTransferService $service) {}

    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'last_12');
        $status = (string) $request->query('status');
        $query = DB::table('warehouse_transfers as t')
            ->join('warehouses as f', 'f.id', '=', 't.from_warehouse_id')->join('warehouses as w', 'w.id', '=', 't.to_warehouse_id')
            ->leftJoin(DB::raw('(SELECT warehouse_transfer_id, COUNT(*) AS line_count, SUM(qty) AS qty, SUM(COALESCE(received_qty, 0)) AS received, SUM(written_off_qty) AS written_off FROM warehouse_transfer_lines GROUP BY warehouse_transfer_id) AS l'), 'l.warehouse_transfer_id', '=', 't.id')
            ->when($status === 'open', fn ($q) => $q->whereIn('t.status', ['sent', 'partial']))
            ->when($status !== '' && $status !== 'open', fn ($q) => $q->where('t.status', $status))
            ->when($status !== 'open', fn ($q) => $q->whereBetween('t.date', [$filters->fromDate(), $filters->toDate()]))
            ->when(is_numeric($request->query('warehouse')), fn ($q) => $q->where(fn ($w) => $w->where('t.from_warehouse_id', (int) $request->query('warehouse'))->orWhere('t.to_warehouse_id', (int) $request->query('warehouse'))))
            ->select('t.id', 't.doc_no', 't.date', 't.status', 't.reason', 't.driver', 't.vehicle_plate', 't.sent_date', 't.received_date',
                'f.name as from_name', 'w.name as to_name', 'l.line_count', 'l.qty', 'l.received', 'l.written_off',
                DB::raw('COALESCE(l.qty, 0) - COALESCE(l.received, 0) - COALESCE(l.written_off, 0) AS in_transit'));
        $statuses = WarehouseTransfer::statusLabels();
        $reasons = WarehouseTransfer::reasonLabels();

        $table = Table::make('transfers', __('Anbar transferləri'))
            ->column('date', __('Tarix'), 'date', ['sql' => 't.date'])
            ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 't.doc_no', 'link' => fn ($r) => route('transfers.show', $r->id)])
            ->column('from_name', __('Haradan'), 'text', ['sql' => 'f.name'])
            ->column('to_name', __('Haraya'), 'text', ['sql' => 'w.name'])
            ->column('reason', __('Səbəb'), 'text', ['sql' => 't.reason', 'mobile' => false, 'format' => fn ($v) => e($reasons[$v] ?? $v)])
            ->column('status', __('Status'), 'text', ['sql' => 't.status', 'format' => fn ($v) => view('transfers._status', ['status' => $v, 'labels' => $statuses])->render()])
            ->column('qty', __('Göndərilən'), 'qty', ['sql' => 'l.qty'])
            ->column('received', __('Qəbul edilən'), 'qty', ['sql' => 'l.received', 'mobile' => false])
            ->column('in_transit', __('Yolda'), 'qty', ['sortable' => false, 'class' => fn ($v, $r) => $v > 0 && in_array($r->status, ['sent', 'partial'], true) ? 'warn font-semibold' : 'text-muted'])
            ->column('driver', __('Sürücü'), 'text', ['sql' => 't.driver', 'mobile' => false, 'format' => fn ($v, $r) => e(trim(($v ?? '').' '.($r->vehicle_plate ?? '')))])
            ->source($query)->sortBy('date', 'desc')->footer(auto: true)
            ->subtitle(__('Göndərilən mal «Yolda (tranzit)» anbarına keçir, qəbul ediləndə təyinat anbarına daxil olur.'));

        if ($response = $this->exportIfRequested($request, [$table], __('Anbar transferləri'), $filters)) {
            return $response;
        }
        $transit = WarehouseTransferService::transitId();

        return view('transfers.index', [
            'title' => __('Anbar transferləri'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request), 'status' => $status, 'statuses' => $statuses,
            'warehouses' => Warehouse::query()->sellable()->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'kpi' => [
                'open' => DB::table('warehouse_transfers')->whereIn('status', ['sent', 'partial'])->count(),
                'draft' => DB::table('warehouse_transfers')->where('status', 'draft')->count(),
                'transit_qty' => (float) DB::table('stock_balances')->where('warehouse_id', $transit)->sum('qty'),
                'transit_value' => (float) DB::table('stock_balances as s')->join('products as p', 'p.id', '=', 's.product_id')->where('s.warehouse_id', $transit)->sum(DB::raw('s.qty * COALESCE(p.current_avg_cost, 0)')),
                'stuck' => DB::table('warehouse_transfers')->whereIn('status', ['sent', 'partial'])->whereDate('sent_date', '<', now()->subDays(3)->toDateString())->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $copy = $request->query('copy') ? WarehouseTransfer::query()->find($request->query('copy')) : null;

        return $this->form($request, null, $copy);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $transfer = DB::transaction(function () use ($data, $request) {
            $transfer = WarehouseTransfer::query()->create($this->header($data) + ['doc_no' => app(NumberingService::class)->next('warehouse_transfer', ['date' => $data['date'], 'workplace_id' => $data['workplace_id'], 'warehouse_id' => $data['from_warehouse_id']]), 'status' => 'draft', 'created_by' => $request->user()->id]);
            $this->saveLines($transfer, $data['lines']);

            return $transfer;
        });

        return $this->afterSave($request, $transfer, true);
    }

    public function show(Request $request, WarehouseTransfer $transfer): View
    {
        // göndərilmə anındakı maya (FIFO) — mənbə anbardan çıxış hərəkətindən
        $moveIds = DB::table('stock_transfers')->where('warehouse_transfer_id', $transfer->id)->where('from_warehouse_id', $transfer->from_warehouse_id)->pluck('product_id', 'id');
        $costs = DB::table('stock_movements')->where('source_type', 'stock_transfer')->where('type', 'transfer_out')->whereIn('source_id', $moveIds->keys()->all() ?: [0])
            ->get(['source_id', 'unit_cost'])->mapWithKeys(fn ($m) => [$moveIds[$m->source_id] => (float) $m->unit_cost]);
        $lines = DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $transfer->id)->orderBy('row_no')->get()
            ->map(fn ($l) => tap($l, function ($x) use ($costs) {
                $x->open = round((float) $x->qty - (float) ($x->received_qty ?? 0) - (float) $x->written_off_qty, 4);
                $x->moved_cost = $costs[$x->product_id] ?? null;
            }));
        $available = $transfer->status === 'draft' ? $this->service->available($transfer->from_warehouse_id, $lines->pluck('product_id')->all()) : [];

        return view($request->boolean('print') ? 'transfers.print' : 'transfers.show', [
            'title' => __('Anbar transferi').' № '.$transfer->doc_no,
            'transfer' => $transfer->load('from', 'to', 'creator', 'sender', 'receiver', 'workplace'), 'lines' => $lines, 'available' => $available,
            'statuses' => WarehouseTransfer::statusLabels(), 'reasons' => WarehouseTransfer::reasonLabels(),
            'writeOffs' => DB::table('stock_write_offs')->where('warehouse_transfer_id', $transfer->id)->get(['id', 'doc_no', 'date', 'total_qty']),
        ]);
    }

    public function edit(Request $request, WarehouseTransfer $transfer): View
    {
        abort_unless($transfer->status === 'draft', 403, __('Yalnız qaralama transfer dəyişdirilir.'));

        return $this->form($request, $transfer, null);
    }

    public function update(Request $request, WarehouseTransfer $transfer): RedirectResponse
    {
        abort_unless($transfer->status === 'draft', 403, __('Yalnız qaralama transfer dəyişdirilir.'));
        $data = $this->validated($request);
        DB::transaction(function () use ($transfer, $data) {
            $transfer->fill($this->header($data))->save();
            $this->saveLines($transfer, $data['lines']);
        });

        return $this->afterSave($request, $transfer, false);
    }

    public function send(Request $request, WarehouseTransfer $transfer): RedirectResponse
    {
        try {
            $this->service->send($transfer, $request->user()->id, null, $request->user()->can('stock.manage') && $request->boolean('allow_negative'));
            if ($request->boolean('receive')) {
                $this->service->receive($transfer->refresh(), $request->user()->id);
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->rebuild();

        return redirect()->route('transfers.show', $transfer)->with('popup', ['title' => $request->boolean('receive') ? __('Transfer tamamlandı') : __('Transfer göndərildi'),
            'message' => $request->boolean('receive') ? __('Mal :to anbarına daxil oldu.', ['to' => $transfer->to->name]) : __('Mal «Yolda (tranzit)» anbarındadır — :to qəbul etməlidir.', ['to' => $transfer->to->name]),
            'docs' => [['type' => __('Anbar transferi'), 'no' => $transfer->doc_no, 'url' => route('transfers.show', $transfer)]]]);
    }

    public function receive(Request $request, WarehouseTransfer $transfer): RedirectResponse
    {
        $data = $request->validate(['received' => ['nullable', 'array'], 'received.*' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'array'], 'notes.*' => ['nullable', 'string', 'max:255']]);
        try {
            $this->service->receive($transfer, $request->user()->id, $data['received'] ?? [], $data['notes'] ?? []);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->rebuild();
        $transfer->refresh();

        return redirect()->route('transfers.show', $transfer)->with('popup', ['title' => __('Qəbul edildi'),
            'message' => $transfer->status === 'partial' ? __('Qismən qəbul: fərq yolda qalır — sonra qəbul edin və ya kəsir kimi silin.') : __('Transfer tam qəbul edildi.'), 'docs' => []]);
    }

    public function shortage(Request $request, WarehouseTransfer $transfer): RedirectResponse
    {
        try {
            $id = $this->service->writeOffShortage($transfer, $request->user()->id, $request->input('note'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->rebuild();
        $doc = DB::table('stock_write_offs')->find($id);

        return redirect()->route('transfers.show', $transfer)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => __('Silinmə aktı'), 'no' => $doc->doc_no, 'url' => route('write-offs.show', $id)]]]);
    }

    public function cancel(Request $request, WarehouseTransfer $transfer): RedirectResponse
    {
        try {
            $this->service->cancel($transfer, $request->input('reason'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->rebuild();

        return redirect()->route('transfers.show', $transfer)->with('status', __('Transfer ləğv edildi.'));
    }

    /**
     * Doldurma tövsiyəsi (JSON).
     */
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'integer', 'exists:warehouses,id'], 'to' => ['required', 'integer', 'exists:warehouses,id', 'different:from'], 'days' => ['nullable', 'integer', 'min:1', 'max:90']]);

        return response()->json($this->service->suggestions((int) $data['from'], (int) $data['to'], (int) ($data['days'] ?? 14)));
    }

    private function form(Request $request, ?WarehouseTransfer $transfer, ?WarehouseTransfer $copy): View
    {
        $source = $transfer ?? $copy;
        $warehouses = Warehouse::query()->where('is_active', true)->where('type', '<>', 'transit')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'type', 'vehicle_plate', 'responsible']);
        $stock = [];
        $reserved = app(SalesDocumentService::class)->reserved();
        foreach (DB::table('stock_balances')->get(['product_id', 'warehouse_id', 'qty']) as $r) {
            $stock[$r->product_id][$r->warehouse_id] = round((float) $r->qty - ($reserved[$r->product_id][$r->warehouse_id] ?? 0), 2);
        }
        $units = DB::table('product_units as pu')->join('units as u', 'u.id', '=', 'pu.unit_id')->get(['pu.product_id', 'pu.id', 'pu.factor', 'pu.is_sale_default', 'u.name'])->groupBy('product_id');
        $unitBarcodes = DB::table('product_barcodes as b')->leftJoin('product_units as pu', 'pu.id', '=', 'b.product_unit_id')->get(['b.product_id', 'b.barcode', 'pu.factor']);
        $products = DB::table('products')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'barcode'])
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'barcode' => $p->barcode, 'stock' => (object) ($stock[$p->id] ?? []),
                'units' => ($units[$p->id] ?? collect())->map(fn ($u) => ['name' => $u->name, 'factor' => (float) $u->factor])->values(),
                'sale_factor' => (float) (($units[$p->id] ?? collect())->firstWhere('is_sale_default', true)?->factor ?? 1)])->values();
        $lines = $source ? DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $source->id)->orderBy('row_no')->get(['product_id', 'qty'])
            ->map(fn ($l) => ['product_id' => $l->product_id, 'qty' => (float) $l->qty])->all() : [];
        $main = $warehouses->firstWhere('type', 'main') ?? $warehouses->first();
        $van = $warehouses->firstWhere('type', 'van') ?? $warehouses->where('id', '<>', $main?->id)->first();

        return view('transfers.form', [
            'title' => $transfer ? __('Anbar transferi').' № '.$transfer->doc_no.' — '.__('düzəliş') : __('Yeni anbar transferi'),
            'transfer' => $transfer, 'warehouses' => $warehouses, 'reasons' => WarehouseTransfer::reasonLabels(),
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'workplaceId' => (int) old('workplace_id', $source->workplace_id ?? Workplace::defaultIdFor($request->user())),
            'config' => [
                'products' => $products,
                'barcodes' => $unitBarcodes->map(fn ($b) => ['barcode' => $b->barcode, 'product_id' => $b->product_id, 'factor' => (float) ($b->factor ?? 1)])->values(),
                'suggestUrl' => route('transfers.suggest'),
                'values' => [
                    'date' => old('date', $transfer?->date?->toDateString() ?? now()->toDateString()),
                    'from' => (int) old('from_warehouse_id', $source->from_warehouse_id ?? $main?->id),
                    'to' => (int) old('to_warehouse_id', $source->to_warehouse_id ?? $van?->id),
                    'reason' => old('reason', $source->reason ?? 'replenish'),
                    'driver' => old('driver', $source->driver ?? ''),
                    'vehicle_plate' => old('vehicle_plate', $source->vehicle_plate ?? ''),
                    'note' => old('note', $source->note ?? ''),
                    'lines' => old('lines', $lines),
                ],
                'warehouses' => $warehouses->map(fn ($w) => ['id' => $w->id, 'plate' => $w->vehicle_plate, 'responsible' => $w->responsible, 'type' => $w->type])->values(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'reason' => ['required', Rule::in(array_keys(WarehouseTransfer::reasonLabels()))],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
            'driver' => ['nullable', 'string', 'max:255'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'then' => ['nullable', Rule::in(['send', 'complete'])],
        ], [], ['to_warehouse_id' => __('Haraya'), 'from_warehouse_id' => __('Haradan'), 'lines' => __('Məhsullar')]);
        $data['workplace_id'] ??= Workplace::defaultIdFor($request->user());

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        return [
            'date' => $data['date'], 'from_warehouse_id' => $data['from_warehouse_id'], 'to_warehouse_id' => $data['to_warehouse_id'],
            'reason' => $data['reason'], 'workplace_id' => $data['workplace_id'], 'driver' => $data['driver'] ?? null, 'vehicle_plate' => $data['vehicle_plate'] ?? null, 'note' => $data['note'] ?? null,
        ];
    }

    /**
     * @param  list<array{product_id: int|string, qty: float|int|string}>  $lines
     */
    private function saveLines(WarehouseTransfer $transfer, array $lines): void
    {
        DB::table('warehouse_transfer_lines')->where('warehouse_transfer_id', $transfer->id)->delete();
        // eyni mal bir sətirdə birləşir
        $merged = [];
        foreach ($lines as $l) {
            $merged[(int) $l['product_id']] = ($merged[(int) $l['product_id']] ?? 0) + (float) $l['qty'];
        }
        $products = DB::table('products')->whereIn('id', array_keys($merged))->get(['id', 'code', 'name'])->keyBy('id');
        $now = now();
        $row = 0;
        foreach ($merged as $id => $qty) {
            DB::table('warehouse_transfer_lines')->insert([
                'warehouse_transfer_id' => $transfer->id, 'row_no' => ++$row, 'product_id' => $id, 'product_code' => $products[$id]->code,
                'product_name' => $products[$id]->name, 'qty' => round($qty, 4), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $transfer->forceFill(['total_qty' => round(array_sum($merged), 4)])->save();
    }

    private function afterSave(Request $request, WarehouseTransfer $transfer, bool $created): RedirectResponse
    {
        $then = $request->input('then');
        $docs = [['type' => __('Anbar transferi'), 'no' => $transfer->doc_no, 'url' => route('transfers.show', $transfer)]];
        if ($then) {
            try {
                $this->service->send($transfer, $request->user()->id);
                if ($then === 'complete') {
                    $this->service->receive($transfer->refresh(), $request->user()->id);
                }
                $this->rebuild();
            } catch (RuntimeException $e) {
                return redirect()->route('transfers.show', $transfer)
                    ->with('popup', ['title' => __('Qaralama saxlanıldı'), 'message' => __('Transfer göndərilmədi: :e', ['e' => $e->getMessage()]), 'docs' => $docs]);
            }
        }
        $title = match ($then) {
            'send' => __('Transfer göndərildi'), 'complete' => __('Transfer tamamlandı'), default => $created ? __('Sənəd yaradıldı') : __('Yadda saxlandı')
        };

        return redirect()->route('transfers.show', $transfer)->with('popup', ['title' => $title, 'message' => $then ? null : __('Qaralama: stok hələ dəyişməyib — «Göndər» basın.'), 'docs' => $created || $then ? $docs : []]);
    }

    private function rebuild(): void
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }
}
