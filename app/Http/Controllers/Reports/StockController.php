<?php

namespace App\Http\Controllers\Reports;

use App\Models\Warehouse;
use App\Reports\Table;
use App\Services\StockService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class StockController extends ReportController
{
    public function __invoke(Request $request, StockService $stock): View|Response
    {
        $filters = $this->filters($request, 'all');
        $asOf = $this->asOf($request);
        $warehouseId = is_numeric($request->query('warehouse')) ? (int) $request->query('warehouse') : null;
        $only = $request->query('only');

        $rows = $stock->balances($filters, $asOf, $warehouseId);
        if ($only === 'low') {
            $rows = $rows->where('low_stock', true)->values();
        } elseif ($only === 'negative') {
            $rows = $rows->filter(fn ($r) => $r->qty < -0.0001)->values();
        } elseif ($only !== 'all') {
            $rows = $rows->filter(fn ($r) => abs($r->qty) > 0.0001)->values();
        }

        $table = Table::make('stock', __('Mal üzrə qalıq'))
            ->column('code', __('Kod'), 'text', ['link' => fn ($r) => route('movements.product', $r->id)])
            ->column('name', __('Mal'), 'text', ['format' => fn ($v, $r) => e($v)
                .($r->low_stock ? ' <span class="badge badge-warn">'.e(__('az qalıb')).'</span>' : '')
                .($r->qty < -0.0001 ? ' <span class="badge badge-neg">'.e(__('mənfi')).'</span>' : '')])
            ->column('category_name', __('Kateqoriya'))
            ->column('qty', __('Qalıq, ədəd'), 'qty', ['class' => fn ($v) => $v < 0 ? 'neg font-semibold' : 'font-semibold'])
            ->column('liters', __('Litr'), 'qty')
            ->column('avg_cost', __('Orta maya'), 'money', ['format' => fn ($v, $r) => e(Fmt::money($v)).($r->cost_estimated ? ' <span class="warn" title="'.e(__('Maya təxminidir — mal kartında redaktə edin')).'">⚠</span>' : '')])
            ->column('value', __('Dəyər (maya)'), 'money')
            ->column('sale_value', __('Dəyər (satış qiyməti)'), 'money')
            ->column('potential_profit', __('Potensial mənfəət'), 'money')
            ->column('last_date', __('Son hərəkət'), 'date')
            ->column('turnover_days', __('Dövriyyə, gün'), 'int', ['format' => fn ($v) => $v === null ? '—' : e(Fmt::number($v)), 'class' => fn ($v) => $v !== null && $v > 180 ? 'warn' : ''])
            ->source($rows)->sortBy('value')->perPage(100)->footer(auto: true)
            ->rowClass(fn ($r) => $r->qty < -0.0001 ? 'row-neg' : ($r->low_stock ? 'row-warn' : ''))
            ->subtitle(__('Dövriyyə günləri = qalıq / son :days günün orta gündəlik satışı', ['days' => StockService::TURNOVER_WINDOW_DAYS]));

        if ($response = $this->exportIfRequested($request, [$table], __('Anbar qalığı').' '.$asOf->format('d.m.Y'), $filters)) {
            return $response;
        }

        $positive = $rows->filter(fn ($r) => $r->qty > 0);

        return view('reports.stock', [
            'title' => __('Anbar qalığı və dəyəri'),
            'filters' => $filters,
            'asOf' => $asOf,
            'warehouseId' => $warehouseId,
            'warehouses' => Warehouse::query()->orderByDesc('is_main')->orderBy('name')->get(),
            'only' => $only,
            'table' => $table,
            'rows' => $table->resolve($request),
            'kpi' => [
                'qty' => $positive->sum('qty'),
                'liters' => $positive->sum('liters'),
                'value' => $positive->sum('value'),
                'sale_value' => $positive->sum('sale_value'),
                'low' => $rows->where('low_stock', true)->count(),
                'negative' => $rows->filter(fn ($r) => $r->qty < -0.0001)->count(),
                'skus' => $positive->count(),
            ],
        ]);
    }

    private function asOf(Request $request): Carbon
    {
        $value = $request->query('asof');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value)->endOfDay();
        }

        return now()->endOfDay();
    }
}
