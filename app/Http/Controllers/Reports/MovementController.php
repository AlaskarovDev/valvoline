<?php

namespace App\Http\Controllers\Reports;

use App\Models\Product;
use App\Reports\Table;
use App\Services\StockService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class MovementController extends ReportController
{
    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_month');
        $type = $request->query('type');
        $query = DB::table('stock_movements as mv')
            ->join('products as p', 'p.id', '=', 'mv.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'mv.warehouse_id')
            ->leftJoin('customers as c', 'c.id', '=', 'mv.customer_id')
            ->whereBetween('mv.date', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->productId, fn ($q) => $q->where('mv.product_id', $filters->productId))
            ->when($filters->groupId, fn ($q) => $q->where('p.product_group_id', $filters->groupId))
            ->when($filters->categoryId, fn ($q) => $q->where('p.category_id', $filters->categoryId))
            ->when($filters->series, fn ($q) => $q->where('p.series', $filters->series))
            ->when($filters->customerId, fn ($q) => $q->where('mv.customer_id', $filters->customerId))
            ->when(is_string($type) && $type !== '', fn ($q) => $q->where('mv.type', $type))
            ->select('mv.*', 'p.code', 'p.name as product_name', 'w.name as warehouse_name', 'c.name as customer_name');

        $table = Table::make('mv', __('Anbar hərəkətləri jurnalı'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'mv.date'])
            ->column('type', __('Tip'), 'text', ['sql' => 'mv.type', 'searchable' => false, 'format' => fn ($v) => self::typeBadge($v)])
            ->column('doc_no', __('Sənəd'), 'text', ['sql' => 'mv.doc_no', 'link' => fn ($r) => $r->source_type === 'sales_line' ? $this->orderLink($r->doc_no) : null])
            ->column('code', __('Kod'), 'text', ['sql' => 'p.code', 'link' => fn ($r) => route('movements.product', $r->product_id)])
            ->column('product_name', __('Mal'), 'text', ['sql' => 'p.name'])
            ->column('warehouse_name', __('Anbar'), 'text', ['sql' => 'w.name'])
            ->column('customer_name', __('Müştəri'), 'text', ['sql' => 'c.name'])
            ->column('qty', __('Miqdar'), 'qty', ['sql' => 'mv.qty', 'class' => fn ($v) => $v < 0 ? 'neg' : 'pos'])
            ->column('unit_cost', __('Vahid maya'), 'money', ['sql' => 'mv.unit_cost'])
            ->column('total_cost', __('Cəmi maya'), 'money', ['sql' => 'mv.total_cost'])
            ->column('running_qty', __('Qalıq (mal)'), 'qty', ['sql' => 'mv.running_qty', 'class' => fn ($v) => $v < 0 ? 'neg' : ''])
            ->column('running_avg_cost', __('Orta maya'), 'money', ['sql' => 'mv.running_avg_cost'])
            ->source($query)->sortBy('date')->perPage(100);

        if ($response = $this->exportIfRequested($request, [$table], __('Anbar hərəkətləri'), $filters)) {
            return $response;
        }
        $summary = (clone $query)->reorder()->select('mv.type')->groupBy('mv.type')->selectRaw('COUNT(*) AS cnt, SUM(mv.qty) AS qty, SUM(mv.total_cost) AS cost')->get()->keyBy('type');

        return view('reports.movements', [
            'title' => __('Anbar hərəkətləri'),
            'filters' => $filters,
            'type' => $type,
            'summary' => $summary,
            'table' => $table,
            'rows' => $table->resolve($request),
        ]);
    }

    public function product(Request $request, Product $product, StockService $stock): View|Response
    {
        $filters = $this->filters($request, 'all');
        $from = $filters->preset === 'all' ? null : $filters->fromDate();
        $to = $filters->preset === 'all' ? null : $filters->toDate();
        $movements = $stock->productCard($product->id, $from, $to);

        $table = Table::make('card', __('Mal kartı: giriş / çıxış / qalıq'))
            ->column('date', __('Tarix'), 'date', ['sortable' => false])
            ->column('type', __('Tip'), 'text', ['sortable' => false, 'format' => fn ($v) => self::typeBadge($v)])
            ->column('doc_no', __('Sənəd'), 'text', ['sortable' => false, 'link' => fn ($r) => $r->source_type === 'sales_line' ? $this->orderLink($r->doc_no) : null])
            ->column('customer_name', __('Müştəri'), 'text', ['sortable' => false])
            ->column('warehouse_name', __('Anbar'), 'text', ['sortable' => false])
            ->column('in', __('Giriş'), 'qty', ['sortable' => false, 'class' => fn ($v) => $v ? 'pos' : ''])
            ->column('out', __('Çıxış'), 'qty', ['sortable' => false, 'class' => fn ($v) => $v ? 'neg' : ''])
            ->column('running_qty', __('Qalıq'), 'qty', ['sortable' => false, 'class' => fn ($v) => $v < 0 ? 'neg font-semibold' : 'font-semibold'])
            ->column('unit_cost', __('Vahid maya'), 'money', ['sortable' => false])
            ->column('running_avg_cost', __('Orta maya'), 'money', ['sortable' => false])
            ->source($movements->map(function ($m) {
                $m->in = $m->qty > 0 ? (float) $m->qty : null;
                $m->out = $m->qty < 0 ? -(float) $m->qty : null;

                return $m;
            })->reverse()->values())
            ->perPage(100)->withoutSearch()
            ->subtitle(__('Ən yeni hərəkət yuxarıda. Transferlər mayaya təsir etmir.'));

        if ($response = $this->exportIfRequested($request, [$table], $product->code.' '.__('mal kartı'), $filters)) {
            return $response;
        }

        $chartRows = $movements->groupBy(fn ($m) => (string) $m->date)->map(fn ($g) => $g->last());
        $chart = [
            'type' => 'line',
            'categories' => $chartRows->keys()->map(fn ($d) => Fmt::date($d))->values()->all(),
            'series' => [
                ['name' => __('Qalıq, ədəd'), 'type' => 'area', 'data' => $chartRows->map(fn ($m) => round((float) $m->running_qty, 2))->values()->all()],
                ['name' => __('Orta maya, ₼'), 'type' => 'line', 'data' => $chartRows->map(fn ($m) => round((float) $m->running_avg_cost, 4))->values()->all()],
            ],
            'format' => 'number',
            'dualAxis' => true,
            'secondFormat' => 'money',
            'strokeWidths' => [2, 2.5],
        ];

        $estimatedLines = DB::table('purchase_lines')->where('product_id', $product->id)->where('is_estimated', true)->orderBy('date')->get();
        $sold = DB::table('sales_lines')->where('product_id', $product->id)
            ->selectRaw("SUM(CASE WHEN op_type = 'sale' THEN qty ELSE 0 END) AS qty, SUM(CASE WHEN op_type = 'sale' THEN net_amount ELSE 0 END) AS amount, SUM(gross_profit) AS profit")->first();

        return view('reports.product', [
            'title' => $product->code.' — '.$product->name,
            'product' => $product->load('group', 'category'),
            'filters' => $filters,
            'table' => $table,
            'rows' => $table->resolve($request),
            'chart' => $chart,
            'estimatedLines' => $estimatedLines,
            'sold' => $sold,
            'siblings' => $product->product_group_id ? Product::query()->where('product_group_id', $product->product_group_id)->where('id', '!=', $product->id)->get(['id', 'code', 'name', 'pack_type', 'stock_qty']) : collect(),
        ]);
    }

    public static function typeBadge(string $type): string
    {
        $class = match ($type) {
            'purchase', 'opening' => 'badge-primary',
            'sale' => 'badge-pos',
            'return' => 'badge-warn',
            'gift', 'internal_use' => 'badge-neg',
            default => '',
        };

        return '<span class="badge '.$class.'">'.e(StockService::typeLabel($type)).'</span>';
    }

    /** @var array<string, int>|null */
    private ?array $orders = null;

    private function orderLink(?string $orderNo): ?string
    {
        if ($orderNo === null) {
            return null;
        }
        $this->orders ??= DB::table('sales_orders')->pluck('id', 'order_no')->mapWithKeys(fn ($id, $no) => [(string) $no => $id])->all();

        return isset($this->orders[$orderNo]) ? route('orders.show', $this->orders[$orderNo]) : null;
    }
}
