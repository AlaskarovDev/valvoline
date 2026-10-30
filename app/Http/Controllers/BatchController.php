<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Jobs\RebuildAnalytics;
use App\Models\Warehouse;
use App\Reports\Table;
use App\Services\AnalyticsRebuilder;
use App\Services\BatchService;
use App\Services\StockWriteOffService;
use App\Support\Fmt;
use App\Support\Spreadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partiyalar və son istifadə tarixləri: vaxtı bitmiş / tezliklə bitəcək mallar, partiya qeydiyyatı,
 * Excel-dən partiya yükləmə və vaxtı bitmiş mal üçün silinmə aktı.
 */
class BatchController extends ReportController
{
    public function index(Request $request, BatchService $batches): View|Response
    {
        $tab = in_array($request->query('tab'), ['expired', 'soon', 'all', 'no_date'], true) ? $request->query('tab') : 'expired';
        $all = $batches->batches();
        $rows = match ($tab) {
            'expired' => $all->where('state', 'expired'),
            'soon' => $all->where('state', 'soon'),
            'no_date' => $all->where('state', 'no_date'),
            default => $all->where('remaining', '>', 0),
        };
        if ($request->query('source') === 'registered') {
            $rows = $rows->where('source', 'registered');
        }
        $states = BatchService::stateLabels();

        $table = Table::make('batches', __('Partiyalar'))
            ->column('code', __('Kod'), 'text', ['link' => fn ($r) => route('products.show', $r->product_id)])
            ->column('name', __('Mal'))
            ->column('batch_no', __('Partiya'), 'text', ['format' => fn ($v, $r) => e($v).($r->estimated ? ' <span class="badge" title="'.e(__('İdxal tarixi + saxlanma müddəti')).'">'.e(__('təxmini')).'</span>' : '')])
            ->column('production_date', __('İstehsal tarixi'), 'date', ['mobile' => false])
            ->column('expiry_date', __('Son istifadə tarixi'), 'date', ['class' => fn ($v, $r) => $r->state === 'expired' ? 'neg font-semibold' : ($r->state === 'soon' ? 'warn font-semibold' : '')])
            ->column('days_left', __('Qalan gün'), 'int', ['format' => fn ($v) => $v === null ? '—' : e(Fmt::number($v)), 'class' => fn ($v) => $v !== null && $v < 0 ? 'neg' : ''])
            ->column('qty', __('Daxil olub'), 'qty', ['mobile' => false])
            ->column('written_off', __('Silinib'), 'qty', ['mobile' => false])
            ->column('remaining', __('Qalıq (FEFO)'), 'qty', ['class' => fn () => 'font-semibold'])
            ->column('value', __('Dəyər (maya)'), 'money')
            ->column('state', __('Vəziyyət'), 'text', ['format' => fn ($v) => '<span class="badge '.($v === 'expired' ? 'badge-neg' : ($v === 'soon' ? 'badge-warn' : '')).'">'.e($states[$v] ?? $v).'</span>'])
            ->column('warehouse_name', __('Anbar'), 'text', ['mobile' => false])
            ->source($rows->values())->sortBy('expiry_date', 'asc')->perPage(100)->footer(auto: true)
            ->subtitle(__('Qalıq FEFO ilə bölüşdürülür: ən tez bitən partiya ilk satılmış sayılır. «Təxmini» — partiya qeyd olunmayıb, idxal tarixi + saxlanma müddəti.'));

        if ($response = $this->exportIfRequested($request, [$table], __('Son istifadə tarixləri'))) {
            return $response;
        }

        return view('batches.index', [
            'title' => __('Son istifadə tarixləri'), 'table' => $table, 'rows' => $table->resolve($request), 'tab' => $tab,
            'kpi' => [
                'expired_qty' => $all->where('state', 'expired')->sum('remaining'), 'expired_value' => $all->where('state', 'expired')->sum('value'),
                'expired_products' => $all->where('state', 'expired')->unique('product_id')->count(),
                'soon_qty' => $all->where('state', 'soon')->sum('remaining'), 'soon_value' => $all->where('state', 'soon')->sum('value'),
                'registered' => $all->where('source', 'registered')->count(), 'no_date' => $all->where('state', 'no_date')->count(),
            ],
            'expiredRows' => $all->where('state', 'expired')->values(),
            'products' => DB::table('products')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'shelf_life_months']),
            'warehouses' => Warehouse::query()->where('is_active', true)->where('type', '<>', 'transit')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'purchaseBatches' => DB::table('purchase_batches')->orderByDesc('date')->limit(200)->get(['id', 'batch_no', 'name', 'date']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('stock.manage'), 403);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'batch_no' => ['required', 'string', 'max:64', Rule::unique('product_batches', 'batch_no')->where('product_id', $request->integer('product_id'))],
            'production_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:production_date'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'purchase_batch_id' => ['nullable', 'integer', 'exists:purchase_batches,id'],
            'received_date' => ['nullable', 'date'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->insertBatch($data, $request->user()->id);

        return back()->with('status', __('Partiya «:no» qeyd olundu.', ['no' => $data['batch_no']]));
    }

    /**
     * Excel: Kod | Partiya | İstehsal tarixi | Son istifadə tarixi | Miqdar.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('stock.manage'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $path = tempnam(sys_get_temp_dir(), 'bat').'.'.strtolower($request->file('file')->getClientOriginalExtension());
        copy($request->file('file')->getRealPath(), $path);
        $products = DB::table('products')->pluck('id', 'code');
        $count = 0;
        $skipped = [];
        $sheet = array_key_first(Spreadsheet::inspect($path));
        foreach ($sheet === null ? [] : Spreadsheet::rows($path, $sheet) as $row) {
            $v = array_values($row);
            $code = Spreadsheet::code($v[0] ?? null);
            $batch = Spreadsheet::text($v[1] ?? null);
            $qty = Spreadsheet::number($v[4] ?? null);
            if ($code === null || $batch === null || ! $qty || ! isset($products[$code])) {
                $code && $skipped[] = $code;

                continue;
            }
            if (DB::table('product_batches')->where('product_id', $products[$code])->where('batch_no', $batch)->exists()) {
                $skipped[] = $code.'/'.$batch;

                continue;
            }
            $this->insertBatch(['product_id' => $products[$code], 'batch_no' => $batch, 'production_date' => Spreadsheet::date($v[2] ?? null)?->toDateString(),
                'expiry_date' => Spreadsheet::date($v[3] ?? null)?->toDateString(), 'qty' => $qty], $request->user()->id);
            $count++;
        }
        @unlink($path);

        return back()->with('popup', ['title' => __('Partiyalar yükləndi'), 'message' => __(':n partiya qeyd olundu.', ['n' => $count]).($skipped ? ' '.__('Ötürüldü: :items', ['items' => implode(', ', array_slice(array_unique($skipped), 0, 20))]) : ''), 'docs' => []]);
    }

    public function destroy(Request $request, int $batch): RedirectResponse
    {
        abort_unless($request->user()->can('stock.manage'), 403);
        if (DB::table('stock_write_off_lines')->where('product_batch_id', $batch)->exists()) {
            return back()->with('error', __('Partiya silinmə aktında istifadə olunub.'));
        }
        DB::table('product_batches')->where('id', $batch)->delete();

        return back()->with('status', __('Partiya silindi.'));
    }

    /**
     * Seçilmiş vaxtı bitmiş partiyalar üçün silinmə aktı.
     */
    public function writeOff(Request $request, BatchService $batches, StockWriteOffService $writeOffs): RedirectResponse
    {
        abort_unless($request->user()->can('stock.manage'), 403);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', 'regex:/^\d+:[^:]*:[0-9.]+$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['items.required' => __('Silinəcək partiyaları seçin.')]);
        $lines = [];
        foreach ($data['items'] as $item) {
            [$productId, $batchId, $qty] = explode(':', $item);
            $lines[] = ['product_id' => (int) $productId, 'batch_id' => $batchId !== '' ? (int) $batchId : null, 'qty' => (float) $qty];
        }
        $doc = $writeOffs->create((int) $data['warehouse_id'], now()->startOfDay(), 'expired', $lines, $request->user()->id, $data['note'] ?? null);
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        return redirect()->route('write-offs.show', $doc)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => __('Silinmə aktı'), 'no' => $doc->doc_no, 'url' => route('write-offs.show', $doc)]]]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertBatch(array $data, ?int $userId): void
    {
        $estimated = false;
        if (empty($data['expiry_date']) && ! empty($data['production_date'])) {
            // son istifadə tarixi yoxdursa — istehsal tarixi + malın saxlanma müddəti
            $months = (int) DB::table('products')->where('id', $data['product_id'])->value('shelf_life_months');
            if ($months > 0) {
                $data['expiry_date'] = Carbon::parse($data['production_date'])->addMonths($months)->toDateString();
                $estimated = true;
            }
        }
        DB::table('product_batches')->insert([
            'product_id' => $data['product_id'], 'batch_no' => $data['batch_no'], 'production_date' => $data['production_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null, 'expiry_estimated' => $estimated, 'qty' => $data['qty'],
            'warehouse_id' => $data['warehouse_id'] ?? null, 'purchase_batch_id' => $data['purchase_batch_id'] ?? null,
            'received_date' => $data['received_date'] ?? now()->toDateString(), 'supplier' => $data['supplier'] ?? null, 'note' => $data['note'] ?? null,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
