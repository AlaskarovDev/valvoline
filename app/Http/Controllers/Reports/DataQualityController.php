<?php

namespace App\Http\Controllers\Reports;

use App\Reports\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Məlumat keyfiyyəti: gözləmədə qalan sətirlər, təxmini maya, mənfi qalığa düşən mallar, uyğunlaşdırılmamış adlar.
 */
class DataQualityController extends ReportController
{
    public const TABLES = [
        'sales_lines' => 'Satış', 'sales_returns' => 'Qaytarma', 'payments' => 'Ödəniş',
        'purchase_lines' => 'İdxal', 'stock_adjustments' => 'Sayım', 'stock_transfers' => 'Transfer',
    ];

    public function __invoke(Request $request): View|Response
    {
        $pending = collect();
        foreach (self::TABLES as $table => $label) {
            $hasCustomer = in_array($table, ['sales_lines', 'sales_returns', 'payments'], true);
            $hasProduct = $table !== 'payments';
            $rows = DB::table($table)->where('status', 'pending')
                ->selectRaw(($hasProduct ? 'product_code, product_id, ' : 'NULL AS product_code, NULL AS product_id, ')
                    .($hasCustomer ? 'customer_code, customer_name, customer_id, ' : 'NULL AS customer_code, NULL AS customer_name, NULL AS customer_id, ')
                    .'COUNT(*) AS cnt, MIN(date) AS first_date, MAX(date) AS last_date')
                ->groupBy(...array_filter([$hasProduct ? 'product_code' : null, $hasProduct ? 'product_id' : null, $hasCustomer ? 'customer_code' : null, $hasCustomer ? 'customer_name' : null, $hasCustomer ? 'customer_id' : null]))
                ->get();
            foreach ($rows as $row) {
                $reasons = [];
                if ($hasProduct && $row->product_id === null) {
                    $reasons[] = 'Tanınmayan mal kodu: '.$row->product_code;
                }
                if ($hasCustomer && $row->customer_id === null) {
                    $reasons[] = 'Tanınmayan müştəri: '.trim(($row->customer_code ?? '').' '.($row->customer_name ?? ''));
                }
                $pending->push((object) [
                    'source' => $label,
                    'reason' => implode(' · ', $reasons),
                    'cnt' => (int) $row->cnt,
                    'first_date' => $row->first_date,
                    'last_date' => $row->last_date,
                    'customer_missing' => $hasCustomer && $row->customer_id === null,
                    'customer_name' => $row->customer_name,
                ]);
            }
        }
        $pendingTable = Table::make('pending', 'Gözləmədə qalan sətirlər')
            ->column('source', 'Mənbə')
            ->column('reason', 'Səbəb', 'text', ['link' => fn ($r) => $r->customer_missing ? route('aliases.index', ['name' => $r->customer_name]) : null])
            ->column('cnt', 'Sətir', 'int')
            ->column('first_date', 'İlk', 'date')
            ->column('last_date', 'Son', 'date')
            ->source($pending)->sortBy('cnt')->perPage(50)
            ->subtitle('Mal kodu əlavə edildikdən və ya ad uyğunlaşdırıldıqdan sonra «Yenidən hesabla» bu sətirləri avtomatik bağlayır');

        $estimated = DB::table('products as p')->where('p.cost_estimated', true)
            ->leftJoin('purchase_lines as l', fn ($j) => $j->on('l.product_id', '=', 'p.id')->where('l.is_estimated', true))
            ->groupBy('p.id', 'p.code', 'p.name', 'p.current_avg_cost', 'p.stock_qty')
            ->selectRaw('p.id, p.code, p.name, p.current_avg_cost, p.stock_qty, SUM(l.qty) AS est_qty, MAX(l.unit_cost) AS est_cost')->get();
        $estimatedTable = Table::make('est', 'Mayası təxmini olan mallar')
            ->column('code', 'Kod', 'text', ['link' => fn ($r) => route('movements.product', $r->id)])
            ->column('name', 'Mal', 'text', ['format' => fn ($v) => '<span class="warn">⚠</span> '.e($v)])
            ->column('est_qty', 'Təxmini miqdar', 'qty')
            ->column('est_cost', 'Təxmini vahid maya', 'money')
            ->column('current_avg_cost', 'Cari orta maya', 'money')
            ->column('stock_qty', 'Cari qalıq', 'qty')
            ->source($estimated)->sortBy('est_qty')->perPage(50);

        $negative = DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.running_qty', '<', -0.0001)
            ->groupBy('m.product_id', 'p.code', 'p.name', 'p.stock_qty')
            ->selectRaw('m.product_id, p.code, p.name, p.stock_qty, MIN(m.date) AS first_negative, MIN(m.running_qty) AS min_qty, COUNT(*) AS cnt')->get();
        $negativeTable = Table::make('neg', 'Mənfi qalığa düşən mallar')
            ->column('code', 'Kod', 'text', ['link' => fn ($r) => route('movements.product', $r->product_id)])
            ->column('name', 'Mal')
            ->column('first_negative', 'İlk dəfə mənfi', 'date')
            ->column('min_qty', 'Ən aşağı qalıq', 'qty', ['class' => fn () => 'neg'])
            ->column('cnt', 'Mənfi hərəkət sayı', 'int')
            ->column('stock_qty', 'Cari qalıq', 'qty', ['class' => fn ($v) => $v < 0 ? 'neg font-semibold' : ''])
            ->source($negative)->sortBy('cnt')->perPage(50)
            ->subtitle('Satış alışdan əvvəl qeydə alınıb və ya alış/açılış qalığı əskikdir');

        $fallback = DB::table('sales_lines')->where('cost_fallback', true)->count();
        $mismatch = DB::table('products')->whereNotNull('current_avg_cost')->whereNotNull('file_avg_cost')->where('file_avg_cost', '>', 0)
            ->get(['id', 'code', 'name', 'current_avg_cost', 'file_avg_cost'])
            ->map(function ($p) {
                $p->diff = (float) $p->current_avg_cost - (float) $p->file_avg_cost;
                $p->diff_pct = $p->diff / (float) $p->file_avg_cost;

                return $p;
            })->filter(fn ($p) => abs($p->diff_pct) > 0.01)->values();
        $mismatchTable = Table::make('cmp', 'Hesablanmış orta maya ≠ fayldakı Orta_Maya_Deyeri (>1%)')
            ->column('code', 'Kod', 'text', ['link' => fn ($r) => route('movements.product', $r->id)])
            ->column('name', 'Mal')
            ->column('file_avg_cost', 'Faylda', 'money')
            ->column('current_avg_cost', 'Sistem', 'money')
            ->column('diff', 'Fərq', 'money')
            ->column('diff_pct', 'Fərq %', 'pct', ['class' => fn ($v) => abs($v) > 0.05 ? 'neg' : 'warn'])
            ->source($mismatch)->sortBy('diff_pct')->perPage(50)
            ->subtitle('Yalnız müqayisə üçündür — sistem mayanı özü hesablayır');

        if ($response = $this->exportIfRequested($request, [$pendingTable, $estimatedTable, $negativeTable, $mismatchTable], 'Məlumat keyfiyyəti')) {
            return $response;
        }

        $unmatchedNames = DB::table('sales_lines')->whereNull('customer_id')->distinct()->count('customer_name')
            + DB::table('payments')->whereNull('customer_id')->distinct()->count('customer_name');

        return view('reports.quality', [
            'title' => 'Məlumat keyfiyyəti',
            'kpi' => [
                'pending' => $pending->sum('cnt'),
                'estimated' => $estimated->count(),
                'negative' => $negative->count(),
                'unmatched' => $unmatchedNames,
                'fallback' => $fallback,
            ],
            'pendingTable' => $pendingTable, 'pendingRows' => $pendingTable->resolve($request),
            'estimatedTable' => $estimatedTable, 'estimatedRows' => $estimatedTable->resolve($request),
            'negativeTable' => $negativeTable, 'negativeRows' => $negativeTable->resolve($request),
            'mismatchTable' => $mismatchTable, 'mismatchRows' => $mismatchTable->resolve($request),
        ]);
    }
}
