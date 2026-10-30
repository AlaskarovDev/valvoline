<?php

namespace App\Http\Controllers\Reports;

use App\Reports\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Müştəri borcları: borclular, borc yaşı (aging), menecer üzrə cəmlər, avans / artıq ödəyənlər.
 */
class DebtController extends ReportController
{
    public function __invoke(Request $request): View|Response
    {
        $filters = $this->filters($request, 'all');
        $tab = $request->query('tab') === 'advances' ? 'advances' : 'debtors';
        $base = fn () => DB::table('customer_balances as b')
            ->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->leftJoin('managers as m', 'm.id', '=', 'c.manager_id')
            ->where('c.type', 'customer')
            ->when($filters->managerId, fn ($q) => $q->where('c.manager_id', $filters->managerId));

        $summary = $base()->selectRaw('
            SUM(CASE WHEN b.balance > 0 THEN b.balance ELSE 0 END) AS debt,
            SUM(CASE WHEN b.balance < 0 THEN -b.balance ELSE 0 END) AS advances,
            SUM(b.balance) AS net,
            SUM(CASE WHEN b.balance > 0.005 THEN 1 ELSE 0 END) AS debtors,
            SUM(CASE WHEN b.balance < -0.005 THEN 1 ELSE 0 END) AS advance_count,
            SUM(b.aging_0_30) AS a0, SUM(b.aging_31_60) AS a31, SUM(b.aging_61_90) AS a61, SUM(b.aging_90_plus) AS a90,
            MAX(b.as_of) AS as_of')->first();

        $list = $base()
            ->when($tab === 'debtors', fn ($q) => $q->where('b.balance', '>', 0.005), fn ($q) => $q->where('b.balance', '<', -0.005))
            ->select('c.id', 'c.code', 'c.name', 'm.name as manager', 'b.balance', 'b.overdue_amount', 'b.overdue_days', 'c.credit_limit', 'b.aging_0_30', 'b.aging_31_60', 'b.aging_61_90', 'b.aging_90_plus',
                'b.oldest_unpaid_date', 'b.last_payment_date', 'b.last_sale_date', 'b.sales_total', 'b.payments_total',
                DB::raw('b.aging_31_60 + b.aging_61_90 + b.aging_90_plus AS min_payment'),
                DB::raw('-b.balance AS advance'));
        $table = Table::make('debts', $tab === 'debtors' ? __('Borclular') : __('Avans / artıq ödəyənlər'))
            ->column('code', __('Kod'), 'text', ['sql' => 'c.code'])
            ->column('name', __('Müştəri'), 'text', ['sql' => 'c.name', 'link' => fn ($r) => route('customers.show', $r->id)])
            ->column('manager', __('Menecer'), 'text', ['sql' => 'm.name']);
        if ($tab === 'debtors') {
            $table->column('balance', __('Borc'), 'money', ['sql' => 'b.balance', 'class' => fn () => 'font-semibold'])
                ->column('overdue_amount', __('Gecikmiş borc'), 'money', ['sql' => 'b.overdue_amount', 'class' => fn ($v) => $v > 0.005 ? 'neg font-semibold' : 'pos'])
                ->column('overdue_days', __('Gecikmə (gün)'), 'int', ['sql' => 'b.overdue_days', 'class' => fn ($v) => $v > 0 ? 'neg' : ''])
                ->column('credit_limit', __('Kredit limiti'), 'money', ['sql' => 'c.credit_limit', 'mobile' => false])
                ->column('min_payment', __('0–30-a düşmək üçün min. ödəniş'), 'money', [
                    'sql' => '(b.aging_31_60 + b.aging_61_90 + b.aging_90_plus)',
                    'class' => fn ($v) => $v > 0.005 ? 'warn font-semibold' : 'pos',
                ])
                ->column('aging_0_30', __('0–30 gün'), 'money', ['sql' => 'b.aging_0_30'])
                ->column('aging_31_60', __('31–60 gün'), 'money', ['sql' => 'b.aging_31_60'])
                ->column('aging_61_90', __('61–90 gün'), 'money', ['sql' => 'b.aging_61_90', 'class' => fn ($v) => $v > 0 ? 'warn' : ''])
                ->column('aging_90_plus', __('90+ gün'), 'money', ['sql' => 'b.aging_90_plus', 'class' => fn ($v) => $v > 0 ? 'neg font-semibold' : ''])
                ->column('oldest_unpaid_date', __('Ən köhnə ödənilməmiş'), 'date', ['sql' => 'b.oldest_unpaid_date']);
        } else {
            $table->column('advance', __('Avans / artıq ödəniş'), 'money', ['sql' => '-b.balance', 'class' => fn () => 'pos font-semibold']);
        }
        $table->column('last_payment_date', __('Son ödəniş'), 'date', ['sql' => 'b.last_payment_date'])
            ->column('last_sale_date', __('Son satış'), 'date', ['sql' => 'b.last_sale_date'])
            ->source($list)->sortBy($tab === 'debtors' ? 'balance' : 'advance')->footer(auto: true)
            ->rowClass(fn ($r) => $tab === 'debtors' && $r->aging_90_plus > 0 ? 'row-warn' : '');

        $byManager = $base()->groupBy('c.manager_id', 'm.name')
            ->selectRaw('c.manager_id, m.name AS manager,
                SUM(CASE WHEN b.balance > 0 THEN b.balance ELSE 0 END) AS debt,
                SUM(CASE WHEN b.balance < 0 THEN -b.balance ELSE 0 END) AS advances,
                SUM(CASE WHEN b.balance > 0.005 THEN 1 ELSE 0 END) AS debtors,
                SUM(b.aging_0_30) AS a0, SUM(b.aging_31_60) AS a31, SUM(b.aging_61_90) AS a61, SUM(b.aging_90_plus) AS a90,
                SUM(b.aging_31_60 + b.aging_61_90 + b.aging_90_plus) AS min_payment')
            ->get();
        $managerTable = Table::make('mgr', __('Menecer üzrə cəmlər'))
            ->column('manager', __('Menecer'), 'text', ['link' => fn ($r) => $r->manager_id && ! $filters->managerLocked ? route('debts.index', ['manager' => $r->manager_id]) : null, 'format' => fn ($v) => e($v ?? '—')])
            ->column('debtors', __('Borclu sayı'), 'int')
            ->column('debt', __('Borc'), 'money')
            ->column('min_payment', __('Min. ödəniş (0–30-a)'), 'money', ['class' => fn ($v) => $v > 0.005 ? 'warn' : ''])
            ->column('a0', __('0–30'), 'money')
            ->column('a31', __('31–60'), 'money')
            ->column('a61', __('61–90'), 'money')
            ->column('a90', __('90+'), 'money', ['class' => fn ($v) => $v > 0 ? 'neg' : ''])
            ->column('advances', __('Avanslar'), 'money')
            ->source($byManager)->sortBy('debt')->footer(auto: true)->withoutSearch();

        if ($response = $this->exportIfRequested($request, [$table, $managerTable], __('Müştəri borcları'), $filters)) {
            return $response;
        }

        $aging = [
            'type' => 'bar',
            'categories' => [__('0–30 gün'), __('31–60 gün'), __('61–90 gün'), __('90+ gün')],
            'series' => [['name' => __('Borc'), 'data' => [round((float) $summary->a0, 2), round((float) $summary->a31, 2), round((float) $summary->a61, 2), round((float) $summary->a90, 2)]]],
            'colors' => ['--chart-4'],
            'columnWidth' => '45%',
        ];

        return view('reports.debts', [
            'title' => __('Müştəri borcları'),
            'filters' => $filters,
            'tab' => $tab,
            'summary' => $summary,
            'aging' => $aging,
            'table' => $table,
            'rows' => $table->resolve($request),
            'managerTable' => $managerTable,
            'managerRows' => $managerTable->resolve($request),
        ]);
    }
}
