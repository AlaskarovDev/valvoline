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
            ->select('c.id', 'c.code', 'c.name', 'm.name as manager', 'b.balance', 'b.aging_0_30', 'b.aging_31_60', 'b.aging_61_90', 'b.aging_90_plus',
                'b.oldest_unpaid_date', 'b.last_payment_date', 'b.last_sale_date', 'b.sales_total', 'b.payments_total',
                DB::raw('-b.balance AS advance'));
        $table = Table::make('debts', $tab === 'debtors' ? 'Borclular' : 'Avans / artıq ödəyənlər')
            ->column('code', 'Kod', 'text', ['sql' => 'c.code'])
            ->column('name', 'Müştəri', 'text', ['sql' => 'c.name', 'link' => fn ($r) => route('customers.show', $r->id)])
            ->column('manager', 'Menecer', 'text', ['sql' => 'm.name']);
        if ($tab === 'debtors') {
            $table->column('balance', 'Borc', 'money', ['sql' => 'b.balance', 'class' => fn () => 'font-semibold'])
                ->column('aging_0_30', '0–30 gün', 'money', ['sql' => 'b.aging_0_30'])
                ->column('aging_31_60', '31–60 gün', 'money', ['sql' => 'b.aging_31_60'])
                ->column('aging_61_90', '61–90 gün', 'money', ['sql' => 'b.aging_61_90', 'class' => fn ($v) => $v > 0 ? 'warn' : ''])
                ->column('aging_90_plus', '90+ gün', 'money', ['sql' => 'b.aging_90_plus', 'class' => fn ($v) => $v > 0 ? 'neg font-semibold' : ''])
                ->column('oldest_unpaid_date', 'Ən köhnə ödənilməmiş', 'date', ['sql' => 'b.oldest_unpaid_date']);
        } else {
            $table->column('advance', 'Avans / artıq ödəniş', 'money', ['sql' => '-b.balance', 'class' => fn () => 'pos font-semibold']);
        }
        $table->column('last_payment_date', 'Son ödəniş', 'date', ['sql' => 'b.last_payment_date'])
            ->column('last_sale_date', 'Son satış', 'date', ['sql' => 'b.last_sale_date'])
            ->source($list)->sortBy($tab === 'debtors' ? 'balance' : 'advance')->footer(auto: true)
            ->rowClass(fn ($r) => $tab === 'debtors' && $r->aging_90_plus > 0 ? 'row-warn' : '');

        $byManager = $base()->groupBy('c.manager_id', 'm.name')
            ->selectRaw('c.manager_id, m.name AS manager,
                SUM(CASE WHEN b.balance > 0 THEN b.balance ELSE 0 END) AS debt,
                SUM(CASE WHEN b.balance < 0 THEN -b.balance ELSE 0 END) AS advances,
                SUM(CASE WHEN b.balance > 0.005 THEN 1 ELSE 0 END) AS debtors,
                SUM(b.aging_0_30) AS a0, SUM(b.aging_31_60) AS a31, SUM(b.aging_61_90) AS a61, SUM(b.aging_90_plus) AS a90')
            ->get();
        $managerTable = Table::make('mgr', 'Menecer üzrə cəmlər')
            ->column('manager', 'Menecer', 'text', ['link' => fn ($r) => $r->manager_id && ! $filters->managerLocked ? route('debts.index', ['manager' => $r->manager_id]) : null, 'format' => fn ($v) => e($v ?? '—')])
            ->column('debtors', 'Borclu sayı', 'int')
            ->column('debt', 'Borc', 'money')
            ->column('a0', '0–30', 'money')
            ->column('a31', '31–60', 'money')
            ->column('a61', '61–90', 'money')
            ->column('a90', '90+', 'money', ['class' => fn ($v) => $v > 0 ? 'neg' : ''])
            ->column('advances', 'Avanslar', 'money')
            ->source($byManager)->sortBy('debt')->footer(auto: true)->withoutSearch();

        if ($response = $this->exportIfRequested($request, [$table, $managerTable], 'Müştəri borcları', $filters)) {
            return $response;
        }

        $aging = [
            'type' => 'bar',
            'categories' => ['0–30 gün', '31–60 gün', '61–90 gün', '90+ gün'],
            'series' => [['name' => 'Borc', 'data' => [round((float) $summary->a0, 2), round((float) $summary->a31, 2), round((float) $summary->a61, 2), round((float) $summary->a90, 2)]]],
            'colors' => ['--chart-4'],
            'columnWidth' => '45%',
        ];

        return view('reports.debts', [
            'title' => 'Müştəri borcları',
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
