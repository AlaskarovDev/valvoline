<?php

namespace App\Http\Controllers\Reports;

use App\Models\PurchaseBatch;
use App\Reports\Table;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * İdxal partiyaları (maşınlar) və partiyadakı malların sonradan satış sürəti.
 */
class PurchaseController extends ReportController
{
    public const SELL_THROUGH_DAYS = 60;

    public function index(Request $request): View|Response
    {
        $batches = DB::table('purchase_batches as b')
            ->leftJoin('purchase_lines as l', 'l.purchase_batch_id', '=', 'b.id')
            ->groupBy('b.id', 'b.batch_no', 'b.name', 'b.date', 'b.date_source', 'b.sku_count', 'b.total_qty', 'b.total_cost')
            ->selectRaw('b.id, b.batch_no, b.name, b.date, b.date_source, b.sku_count, b.total_qty, b.total_cost,
                COUNT(l.id) AS line_count, SUM(l.qty) AS qty, SUM(l.total_cost) AS cost, SUM(CASE WHEN l.is_estimated THEN 1 ELSE 0 END) AS estimated,
                SUM(CASE WHEN l.product_id IS NULL THEN 1 ELSE 0 END) AS pending')
            ->orderBy('b.date')->get()
            ->map(function ($b) {
                $products = DB::table('purchase_lines')->where('purchase_batch_id', $b->id)->whereNotNull('product_id')->pluck('product_id');
                $end = Carbon::parse($b->date)->addDays(self::SELL_THROUGH_DAYS)->toDateString();
                $sold = (float) DB::table('sales_lines')->whereIn('product_id', $products)->where('op_type', 'sale')
                    ->whereBetween('date', [$b->date, $end])->sum('qty');
                $b->sold_60 = $sold;
                $b->sell_through = (float) $b->qty > 0 ? $sold / (float) $b->qty : null;
                $b->avg_cost = (float) $b->qty > 0 ? (float) $b->cost / (float) $b->qty : null;

                return $b;
            });

        $table = Table::make('batches', 'İdxal partiyaları')
            ->column('batch_no', '№', 'int', ['link' => fn ($r) => route('purchases.show', $r->id)])
            ->column('name', 'Partiya', 'text', ['link' => fn ($r) => route('purchases.show', $r->id), 'format' => fn ($v, $r) => e($v ?? '—').($r->batch_no == 0 ? ' <span class="badge">açılış</span>' : '').($r->estimated > 0 ? ' <span class="warn" title="Təxmini maya">⚠</span>' : '')])
            ->column('date', 'Tarix', 'date')
            ->column('line_count', 'Çeşid', 'int')
            ->column('qty', 'Ədəd', 'qty')
            ->column('cost', 'Maya cəmi', 'money')
            ->column('avg_cost', 'Orta vahid maya', 'money')
            ->column('sold_60', 'Satılıb ('.self::SELL_THROUGH_DAYS.' gün)', 'qty')
            ->column('sell_through', 'Satış sürəti', 'pct', ['class' => fn ($v) => $v !== null && $v >= 0.8 ? 'pos font-semibold' : ($v !== null && $v < 0.3 ? 'warn' : '')])
            ->source($batches)->sortBy('date')->perPage(100)->footer(auto: true)
            ->subtitle('Satış sürəti = partiyadakı mallardan ilk '.self::SELL_THROUGH_DAYS.' gündə satılan miqdar / partiya miqdarı (eyni kodların bütün satışları)');

        if ($response = $this->exportIfRequested($request, [$table], 'İdxal partiyaları')) {
            return $response;
        }

        $chart = [
            'type' => 'bar',
            'categories' => $batches->map(fn ($b) => ($b->name ?? '№'.$b->batch_no).' · '.Fmt::date($b->date))->all(),
            'series' => [['name' => 'Maya cəmi', 'data' => $batches->map(fn ($b) => round((float) $b->cost, 2))->all()]],
            'links' => $batches->map(fn ($b) => route('purchases.show', $b->id))->all(),
        ];

        return view('reports.purchases', ['title' => 'İdxal partiyaları', 'table' => $table, 'rows' => $table->resolve($request), 'chart' => $chart]);
    }

    public function show(Request $request, PurchaseBatch $batch): View|Response
    {
        $nextDate = DB::table('purchase_batches')->where('date', '>', $batch->date)->min('date');
        $end = $nextDate ?? now()->toDateString();
        $days = max(1, Carbon::parse($batch->date)->diffInDays(Carbon::parse($end)));

        $lines = DB::table('purchase_lines as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.purchase_batch_id', $batch->id)
            ->select('l.*', 'p.name as pname', 'p.stock_qty', 'p.current_avg_cost')->orderBy('l.row_no')->get();
        $sold = DB::table('sales_lines')->whereIn('product_id', $lines->pluck('product_id')->filter())->where('op_type', 'sale')
            ->whereBetween('date', [$batch->date->toDateString(), $end])
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty) AS qty')->pluck('qty', 'product_id');
        $rows = $lines->map(function ($l) use ($sold, $days) {
            $l->sold = (float) ($sold[$l->product_id] ?? 0);
            $l->daily = $l->sold / $days;
            $l->days_to_sell = $l->daily > 0 ? (float) $l->qty / $l->daily : null;
            $l->sell_through = (float) $l->qty > 0 ? $l->sold / (float) $l->qty : null;

            return $l;
        });

        $table = Table::make('lines', 'Partiyanın malları')
            ->column('product_code', 'Kod', 'text', ['link' => fn ($r) => $r->product_id ? route('movements.product', $r->product_id) : null])
            ->column('pname', 'Mal', 'text', ['format' => fn ($v, $r) => e($v ?? $r->product_name).($r->is_estimated ? ' <span class="warn" title="'.e($r->note).'">⚠ təxmini</span>' : '').($r->product_id ? '' : ' <span class="badge badge-warn">gözləmədə</span>')])
            ->column('qty', 'Miqdar', 'qty')
            ->column('unit_cost', 'Vahid maya', 'money')
            ->column('total_cost', 'Cəmi maya', 'money')
            ->column('sold', 'Satılıb (növbəti partiyaya qədər)', 'qty')
            ->column('sell_through', 'Satış sürəti', 'pct')
            ->column('days_to_sell', 'Satılma müddəti, gün', 'int', ['format' => fn ($v) => $v === null ? '—' : e(Fmt::number($v))])
            ->column('stock_qty', 'Cari qalıq', 'qty')
            ->source($rows)->sortBy('total_cost')->perPage(200)->footer(auto: true)
            ->subtitle('Dövr: '.Fmt::date($batch->date).' – '.Fmt::date($end).' ('.$days.' gün)');

        if ($response = $this->exportIfRequested($request, [$table], 'Partiya '.$batch->batch_no)) {
            return $response;
        }

        return view('reports.purchase', [
            'title' => ($batch->name ?? 'Partiya').' · №'.$batch->batch_no,
            'batch' => $batch,
            'table' => $table,
            'rows' => $table->resolve($request),
            'summary' => (object) ['qty' => $rows->sum('qty'), 'cost' => $rows->sum('total_cost'), 'sold' => $rows->sum('sold'), 'skus' => $rows->count()],
        ]);
    }
}
