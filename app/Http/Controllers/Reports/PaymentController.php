<?php

namespace App\Http\Controllers\Reports;

use App\Reports\Table;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends ReportController
{
    public function __invoke(Request $request): View|Response
    {
        $filters = $this->filters($request);
        $base = fn () => DB::table('payments as py')
            ->leftJoin('customers as c', 'c.id', '=', 'py.customer_id')
            ->leftJoin('managers as m', 'm.id', '=', 'py.manager_id')
            ->whereBetween('py.date', [$filters->fromDate(), $filters->toDate()])
            ->when($filters->managerId, fn ($q) => $q->where('py.manager_id', $filters->managerId))
            ->when($filters->customerId, fn ($q) => $q->where('py.customer_id', $filters->customerId));

        $totals = $base()->selectRaw('SUM(py.cash) AS cash, SUM(py.bank) AS bank, SUM(py.total) AS total, COUNT(*) AS cnt, SUM(CASE WHEN py.total < 0 THEN py.total ELSE 0 END) AS refunds')->first();

        $monthly = $base()->groupBy('py.period')->orderBy('py.period')
            ->selectRaw('py.period, SUM(py.cash) AS cash, SUM(py.bank) AS bank')->get();
        $chart = [
            'type' => 'bar',
            'stacked' => true,
            'categories' => $monthly->map(fn ($r) => Fmt::period($r->period))->all(),
            'series' => [
                ['name' => 'Nağd', 'data' => $monthly->map(fn ($r) => round((float) $r->cash, 2))->all()],
                ['name' => 'Bank', 'data' => $monthly->map(fn ($r) => round((float) $r->bank, 2))->all()],
            ],
            'colors' => ['--chart-3', '--chart-2'],
        ];

        $receivers = $base()->groupBy('py.received_by')
            ->selectRaw("COALESCE(py.received_by, '—') AS received_by, COUNT(*) AS cnt, SUM(py.cash) AS cash, SUM(py.bank) AS bank, SUM(py.total) AS total")->get();
        $receiverTable = Table::make('rcv', 'Təhvil alan üzrə')
            ->column('received_by', 'Təhvil alan')
            ->column('cnt', 'Say', 'int')
            ->column('cash', 'Nağd', 'money')
            ->column('bank', 'Bank', 'money')
            ->column('total', 'Cəmi', 'money')
            ->source($receivers)->sortBy('total')->footer(auto: true)->withoutSearch();

        $managers = $base()->groupBy('py.manager_id', 'm.name')
            ->selectRaw("py.manager_id, COALESCE(m.name, '—') AS manager, COUNT(*) AS cnt, SUM(py.cash) AS cash, SUM(py.bank) AS bank, SUM(py.total) AS total")->get();
        $managerTable = Table::make('mgr', 'Menecer üzrə')
            ->column('manager', 'Menecer')
            ->column('cnt', 'Say', 'int')
            ->column('cash', 'Nağd', 'money')
            ->column('bank', 'Bank', 'money')
            ->column('total', 'Cəmi', 'money')
            ->source($managers)->sortBy('total')->footer(auto: true)->withoutSearch();

        $listTable = Table::make('pay', 'Ödənişlər')
            ->column('date', 'Tarix', 'date', ['sql' => 'py.date'])
            ->column('customer', 'Müştəri', 'text', ['sql' => 'COALESCE(c.name, py.customer_name)', 'link' => fn ($r) => $r->customer_id ? route('customers.show', $r->customer_id) : null])
            ->column('manager', 'Menecer', 'text', ['sql' => 'm.name'])
            ->column('cash', 'Nağd', 'money', ['sql' => 'py.cash'])
            ->column('bank', 'Bank', 'money', ['sql' => 'py.bank'])
            ->column('total', 'Cəmi', 'money', ['sql' => 'py.total', 'class' => fn ($v) => $v < 0 ? 'neg' : 'font-semibold'])
            ->column('received_by', 'Təhvil alan', 'text', ['sql' => 'py.received_by'])
            ->column('note', 'Qeyd', 'text', ['sql' => 'py.note'])
            ->source($base()->select('py.date', 'py.customer_id', 'py.cash', 'py.bank', 'py.total', 'py.received_by', 'py.note',
                DB::raw('COALESCE(c.name, py.customer_name) AS customer'), 'm.name as manager'))
            ->sortBy('date')->footer(auto: true)
            ->rowClass(fn ($r) => $r->total < 0 ? 'row-neg' : '')
            ->subtitle('Mənfi məbləğ — müştəriyə geri ödəniş');

        if ($response = $this->exportIfRequested($request, [$receiverTable, $managerTable, $listTable], 'Ödənişlər', $filters)) {
            return $response;
        }

        return view('reports.payments', [
            'title' => 'Ödənişlər',
            'filters' => $filters,
            'totals' => $totals,
            'chart' => $chart,
            'receiverTable' => $receiverTable, 'receiverRows' => $receiverTable->resolve($request),
            'managerTable' => $managerTable, 'managerRows' => $managerTable->resolve($request),
            'listTable' => $listTable, 'listRows' => $listTable->resolve($request),
        ]);
    }
}
