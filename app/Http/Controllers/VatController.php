<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Reports\Table;
use App\Services\VatLedgerService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ƏDV və ƏDV depozit hesabı: aylar üzrə hesablanmış / əvəzləşdirilən ƏDV, depozit qalığı, tərəflər üzrə ƏDV borcu.
 */
class VatController extends ReportController
{
    public function index(Request $request, VatLedgerService $ledger): View|Response
    {
        $years = DB::table('sales_lines')->selectRaw('DISTINCT SUBSTR(period, 1, 4) AS y')->pluck('y')->map(fn ($y) => (int) $y)
            ->push((int) now()->year)->unique()->sortDesc()->values()->all();
        $year = in_array($request->integer('year'), $years, true) ? $request->integer('year') : $years[0];
        $tab = in_array($request->query('tab'), ['customers', 'suppliers'], true) ? $request->query('tab') : 'months';

        if ($tab === 'customers') {
            $table = Table::make('vatc', __('Müştərilər üzrə ƏDV'))
                ->column('code', __('Kod'), 'text')
                ->column('name', __('Müştəri'), 'text', ['link' => fn ($r) => route('customers.show', $r->id), 'wrap' => true])
                ->column('voen', __('VÖEN'), 'text', ['mobile' => false])
                ->column('vat', __('Fakturalanmış ƏDV'), 'money')
                ->column('received', __('Depozitə daxil olan'), 'money')
                ->column('open', __('ƏDV borcu'), 'money', ['class' => fn ($v) => $v > 0.005 ? 'neg font-semibold' : 'pos'])
                ->source($ledger->customers($request->user()->restrictedManagerId()))->sortBy('open', 'desc')->footer(auto: true);
        } elseif ($tab === 'suppliers') {
            $table = Table::make('vats', __('Təchizatçılar üzrə ƏDV'))
                ->column('name', __('Təchizatçı'), 'text', ['wrap' => true])
                ->column('tin', __('VÖEN'), 'text')
                ->column('vat', __('e-qaimə ƏDV-si'), 'money')
                ->column('paid', __('Depozitdən ödənilən'), 'money')
                ->column('open', __('Ödəniləcək ƏDV'), 'money', ['class' => fn ($v) => $v > 0.005 ? 'warn font-semibold' : 'pos'])
                ->source($ledger->suppliers())->sortBy('open', 'desc')->footer(auto: true);
        } else {
            $months = collect($ledger->monthly($year))->map(fn ($m, $period) => (object) (['period' => $period] + $m))->values();
            $table = Table::make('vatm', __('Aylar üzrə ƏDV'))
                ->column('period', __('Ay'), 'text', ['format' => fn ($v) => e(Fmt::period($v))])
                ->column('output', __('Satışın ƏDV-si'), 'money')
                ->column('returns', __('Qaytarmanın ƏDV-si'), 'money', ['mobile' => false])
                ->column('input_einvoice', __('Alış ƏDV-si (e-qaimə)'), 'money')
                ->column('input_docs', __('Alış ƏDV-si (mədaxil qaimələri)'), 'money', ['mobile' => false, 'class' => fn () => 'text-muted'])
                ->column('payable', __('Büdcəyə (təxmini)'), 'money', ['class' => fn ($v) => $v > 0.005 ? 'font-semibold' : 'pos'])
                ->column('deposit_in', __('Depozitə daxil'), 'money', ['mobile' => false])
                ->column('deposit_out', __('Depozitdən çıxış'), 'money', ['mobile' => false])
                ->column('deposit_balance', __('Depozit qalığı (ay sonu)'), 'money')
                ->source($months)->footer([
                    'period' => __('Cəmi'), 'deposit_balance' => $months->whereNotNull('deposit_balance')->last()?->deposit_balance,
                    ...collect(['output', 'returns', 'input_einvoice', 'input_docs', 'payable', 'deposit_in', 'deposit_out'])->mapWithKeys(fn ($k) => [$k => $months->sum($k)])->all(),
                ]);
        }

        if ($response = $this->exportIfRequested($request, [$table], __('ƏDV və depozit hesabı'))) {
            return $response;
        }

        return view('vat.index', ['title' => __('ƏDV və depozit hesabı'), 'year' => $year, 'years' => $years, 'tab' => $tab, 'table' => $table, 'rows' => $table->resolve($request),
            'accounts' => $ledger->depositAccounts(), 'hasEinvoices' => DB::table('incoming_einvoices')->exists()]);
    }
}
