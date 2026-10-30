<?php

namespace App\Http\Controllers\Reports;

use App\Models\Customer;
use App\Reports\ReportFilters;
use App\Reports\Table;
use App\Services\CreditControl;
use App\Services\CustomerBalanceService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends ReportController
{
    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'all');
        $tab = $request->query('tab') === 'inactive' ? 'inactive' : 'all';
        $days = max(1, (int) $request->query('days', 60));
        $asOf = (string) (DB::table('customer_balances')->max('as_of') ?? now()->toDateString());

        $query = DB::table('customers as c')
            ->leftJoin('customer_balances as b', 'b.customer_id', '=', 'c.id')
            ->leftJoin('managers as m', 'm.id', '=', 'c.manager_id')
            ->when($filters->managerId, fn ($q) => $q->where('c.manager_id', $filters->managerId))
            ->when($request->query('type') === 'internal', fn ($q) => $q->where('c.type', 'internal'), fn ($q) => $q->when($request->query('type') !== 'any', fn ($q2) => $q2->where('c.type', 'customer')))
            ->when($tab === 'inactive', fn ($q) => $q->whereNotNull('b.last_sale_date')
                ->where('b.last_sale_date', '<', Carbon::parse($asOf)->subDays($days)->toDateString()))
            ->when($request->query('only') === 'overdue', fn ($q) => $q->where('b.overdue_amount', '>', 0.005))
            ->when($request->query('only') === 'blocked', fn ($q) => $q->where('c.status', 'blocked'))
            ->select('c.id', 'c.code', 'c.name', 'c.voen', 'c.type', 'c.region', 'c.status', 'c.credit_limit', 'c.payment_days', 'b.overdue_amount', 'b.overdue_days', 'm.name as manager',
                'b.first_sale_date', 'b.last_sale_date', 'b.sales_total', 'b.returns_total', 'b.payments_total', 'b.balance', 'b.last_payment_date', 'b.order_count',
                DB::raw('CASE WHEN b.order_count > 0 THEN b.sales_total / b.order_count ELSE NULL END AS avg_order'));

        $table = Table::make('customers', $tab === 'inactive' ? __(':days+ gündür alış etməyən müştərilər', ['days' => $days]) : __('Müştəri bazası'))
            ->column('code', __('Kod'), 'text', ['sql' => 'c.code'])
            ->column('name', __('Müştəri'), 'text', ['sql' => 'c.name', 'link' => fn ($r) => route('customers.show', $r->id),
                'format' => fn ($v, $r) => e($v).($r->status === 'blocked' ? ' <span class="badge badge-neg">'.e(__('bloklu')).'</span>' : '')])
            ->column('manager', __('Menecer'), 'text', ['sql' => 'm.name'])
            ->column('region', __('Region'), 'text', ['sql' => 'c.region', 'mobile' => false])
            ->column('voen', __('VÖEN'), 'text', ['sql' => 'c.voen'])
            ->column('first_sale_date', __('İlk satış'), 'date', ['sql' => 'b.first_sale_date'])
            ->column('last_sale_date', __('Son satış'), 'date', ['sql' => 'b.last_sale_date'])
            ->column('sales_total', __('Dövriyyə'), 'money', ['sql' => 'b.sales_total'])
            ->column('payments_total', __('Ödəniş'), 'money', ['sql' => 'b.payments_total'])
            ->column('balance', __('Balans'), 'money', ['sql' => 'b.balance', 'class' => fn ($v) => $v > 0.005 ? 'warn font-semibold' : ($v < -0.005 ? 'pos font-semibold' : '')])
            ->column('overdue_amount', __('Gecikmiş borc'), 'money', ['sql' => 'b.overdue_amount', 'class' => fn ($v) => $v > 0.005 ? 'neg font-semibold' : ''])
            ->column('credit_limit', __('Kredit limiti'), 'money', ['sql' => 'c.credit_limit', 'mobile' => false])
            ->column('last_payment_date', __('Son ödəniş'), 'date', ['sql' => 'b.last_payment_date'])
            ->column('order_count', __('Sifariş'), 'int', ['sql' => 'b.order_count'])
            ->column('avg_order', __('Orta sifariş'), 'money', ['sql' => 'CASE WHEN b.order_count > 0 THEN b.sales_total / b.order_count ELSE NULL END'])
            ->source($query)->sortBy($tab === 'inactive' ? 'last_sale_date' : 'sales_total', $tab === 'inactive' ? 'asc' : 'desc')
            ->footer(auto: true)
            ->subtitle(__('Balans: müsbət — müştəri borcludur, mənfi — avans / artıq ödəniş'));

        if ($response = $this->exportIfRequested($request, [$table], __('Müştərilər'), $filters)) {
            return $response;
        }

        return view('reports.customers', [
            'title' => __('Müştəri bazası'),
            'filters' => $filters,
            'tab' => $tab,
            'days' => $days,
            'table' => $table,
            'rows' => $table->resolve($request),
            'counts' => [
                'all' => DB::table('customers')->where('type', 'customer')->when($filters->managerId, fn ($q) => $q->where('manager_id', $filters->managerId))->count(),
            ],
        ]);
    }

    /**
     * Üzləşmə aktı (çap forması): dövrün əvvəlinə qalıq, əməliyyatlar, son qalıq, iki tərəfin imzası.
     */
    public function akt(Request $request, Customer $customer, CustomerBalanceService $balances): View
    {
        $user = $request->user();
        abort_if($user->restrictedManagerId() !== null && $customer->manager_id !== $user->restrictedManagerId(), 403);
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();

        return view('customers.akt', ['customer' => $customer, 'from' => $from, 'to' => $to, 'statement' => $balances->statement($customer->id, $from, $to)]);
    }

    public function show(Request $request, Customer $customer, CustomerBalanceService $balances): View|Response
    {
        $user = $request->user();
        abort_if($user->restrictedManagerId() !== null && $customer->manager_id !== $user->restrictedManagerId(), 403);

        $filters = ReportFilters::fromRequest($request, 'all');
        $from = $filters->preset === 'all' ? null : $filters->fromDate();
        $to = $filters->preset === 'all' ? null : $filters->toDate();
        $statement = $balances->statement($customer->id, $from, $to);
        $orderIds = DB::table('sales_orders')->where('customer_id', $customer->id)->pluck('id', 'order_no')
            ->mapWithKeys(fn ($id, $no) => [(string) $no => $id])->all();

        $statementTable = Table::make('akt', __('Akt-sverka'))
            ->column('date', __('Tarix'), 'date', ['sortable' => false])
            ->column('type', __('Əməliyyat'), 'text', ['sortable' => false, 'format' => fn ($v) => '<span class="badge '.match ($v) {
                'Satış' => 'badge-primary', 'Qaytarma' => 'badge-warn', 'Ödəniş' => 'badge-pos', default => 'badge-neg',
            }.'">'.e(__($v)).'</span>'])
            ->column('doc', __('Sənəd'), 'text', ['sortable' => false, 'link' => fn ($r) => $r['type'] === 'Satış' && isset($orderIds[(string) $r['doc']]) ? route('orders.show', $orderIds[(string) $r['doc']]) : null])
            ->column('description', __('Təsvir'), 'text', ['sortable' => false])
            ->column('debit', __('Debet (borc)'), 'money', ['sortable' => false])
            ->column('credit', __('Kredit (ödəniş)'), 'money', ['sortable' => false])
            ->column('balance', __('Qalıq'), 'money', ['sortable' => false, 'class' => fn ($v) => $v > 0.005 ? 'warn font-semibold' : 'font-semibold'])
            ->source(collect($statement['rows']))->perPage(100)->withoutSearch()
            ->subtitle(__('İlkin qalıq: :opening · Son qalıq: :closing', ['opening' => Fmt::money($statement['opening']), 'closing' => Fmt::money($statement['closing'])]));
        $statementTable->footer(['debit' => array_sum(array_column($statement['rows'], 'debit')), 'credit' => array_sum(array_column($statement['rows'], 'credit')), 'balance' => $statement['closing']]);

        $products = DB::table('sales_lines as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.customer_id', $customer->id)->where('l.op_type', 'sale')
            ->when($from, fn ($q) => $q->whereBetween('l.date', [$from, $to]))
            ->groupBy('l.product_id', 'l.product_code', 'p.name')
            ->selectRaw('l.product_id, l.product_code AS code, COALESCE(p.name, l.product_code) AS name, SUM(l.qty) AS qty, SUM(l.net_amount) AS amount, SUM(l.gross_profit) AS profit, MAX(l.date) AS last_date, COUNT(DISTINCT l.sales_order_id) AS orders')
            ->get()->map(function ($r) {
                $r->margin = (float) $r->amount != 0 ? (float) $r->profit / (float) $r->amount : null;

                return $r;
            });
        $productTable = Table::make('cp', __('Aldığı mallar'))
            ->column('code', __('Kod'), 'text', ['link' => fn ($r) => $r->product_id ? route('movements.product', $r->product_id) : null])
            ->column('name', __('Mal'))
            ->column('orders', __('Sifariş'), 'int')
            ->column('qty', __('Miqdar'), 'qty')
            ->column('amount', __('Məbləğ'), 'money')
            ->column('profit', __('Qazanc'), 'money')
            ->column('margin', __('Marja'), 'pct')
            ->column('last_date', __('Son alış'), 'date')
            ->source($products)->sortBy('amount')->perPage(25)->footer(auto: true);

        if ($response = $this->exportIfRequested($request, [$statementTable, $productTable], $customer->name, $filters)) {
            return $response;
        }

        $monthly = DB::table('monthly_sales_summary')->where('customer_id', $customer->id)
            ->groupBy('period')->orderBy('period')
            ->selectRaw("period, SUM(CASE WHEN kind = 'sale' THEN net_amount ELSE 0 END) AS sales, SUM(CASE WHEN kind = 'return_customer' THEN net_amount ELSE 0 END) AS returns")
            ->get();
        $payments = DB::table('payments')->where('customer_id', $customer->id)->groupBy('period')->selectRaw('period, SUM(total) AS total')->pluck('total', 'period');
        $periods = $monthly->pluck('period')->merge($payments->keys())->unique()->sort()->values();
        $monthlyChart = [
            'type' => 'bar',
            'categories' => $periods->map(fn ($p) => Fmt::period($p))->all(),
            'series' => [
                ['name' => __('Alış (satış)'), 'data' => $periods->map(fn ($p) => round((float) ($monthly->firstWhere('period', $p)->sales ?? 0), 2))->all()],
                ['name' => __('Ödəniş'), 'data' => $periods->map(fn ($p) => round((float) ($payments[$p] ?? 0), 2))->all()],
            ],
            'colors' => ['--chart-1', '--chart-3'],
            'columnWidth' => '65%',
        ];

        return view('reports.customer', [
            'title' => $customer->name,
            'customer' => $customer->load('manager', 'aliases'),
            'balance' => $customer->balance,
            'credit' => app(CreditControl::class)->snapshot($customer),
            'canEdit' => $user->can('customers') && ($user->restrictedManagerId() === null || $customer->manager_id === $user->restrictedManagerId()),
            'filters' => $filters,
            'statementTable' => $statementTable,
            'statementRows' => $statementTable->resolve($request),
            'productTable' => $productTable,
            'productRows' => $productTable->resolve($request),
            'monthlyChart' => $monthlyChart,
        ]);
    }
}
