<?php

namespace App\Services;

use App\Support\NameNormalizer;
use App\Support\Vat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ƏDV uçotu və ƏDV depozit hesabı.
 *  - Hesablanmış ƏDV: satış sətirlərinin ƏDV-si (faktura sətrində yazılmış; idxal olunmuş satışda malın ƏDV rejimi ilə) − qaytarmaların ƏDV-si;
 *  - Əvəzləşdirilən ƏDV: daxil olan e-qaimələrin ƏDV-si (qaytarma e-qaiməsi azaldır); müqayisə üçün mədaxil qaimələrinin ƏDV-si;
 *  - Depozit hesabı: «depozit» növlü bank hesablarının ay sonu qalığı (mühasibat faylı) və çıxarış əməliyyatları;
 *  - Tərəflər üzrə: müştərinin ödəməli olduğu ƏDV ↔ depozitə daxil olan (çıxarışda müştərinin VÖEN-i ilə),
 *    təchizatçıya ödənəcək ƏDV ↔ depozitdən çıxan (təchizatçının VÖEN-i ilə).
 */
class VatLedgerService
{
    /**
     * @return array<string, array{output: float, returns: float, input_einvoice: float, input_docs: float, payable: float, deposit_in: float, deposit_out: float, deposit_balance: float|null}>
     */
    public function monthly(int $year): array
    {
        $months = [];
        $last = $year === (int) now()->year ? (int) now()->month : 12;
        for ($m = 1; $m <= $last; $m++) {
            $months[sprintf('%04d-%02d', $year, $m)] = ['output' => 0.0, 'returns' => 0.0, 'input_einvoice' => 0.0, 'input_docs' => 0.0, 'payable' => 0.0,
                'deposit_in' => 0.0, 'deposit_out' => 0.0, 'deposit_balance' => null];
        }
        $add = function (string $period, string $key, float $value) use (&$months) {
            if (isset($months[$period])) {
                $months[$period][$key] += $value;
            }
        };

        foreach ($this->salesVat($year) as $r) {
            $add($r['period'], 'output', $r['vat']);
        }
        foreach ($this->returnVat($year) as $period => $vat) {
            $add($period, 'returns', $vat);
        }
        foreach (DB::table('incoming_einvoices')->whereYear('issued_at', $year)->get(['issued_at', 'kind', 'status', 'vat_amount']) as $e) {
            if (preg_match('/ləğv|silin|rədd/iu', (string) $e->status)) {
                continue;
            }
            $add(substr((string) $e->issued_at, 0, 7), 'input_einvoice', (preg_match('/qaytar/iu', (string) $e->kind) ? -1 : 1) * (float) $e->vat_amount);
        }
        foreach (DB::table('purchase_documents')->whereNull('cancelled_at')->whereYear('date', $year)->get(['date', 'type', 'vat_amount', 'rate']) as $d) {
            $add(substr((string) $d->date, 0, 7), 'input_docs', ($d->type === 'return' ? -1 : 1) * (float) $d->vat_amount * (float) $d->rate);
        }

        $accounts = $this->depositAccounts();
        if ($accounts->isNotEmpty()) {
            foreach (DB::table('bank_transactions')->whereIn('bank_account_id', $accounts->pluck('id'))->whereYear('date', $year)
                ->get(['date', 'amount_in', 'amount_out']) as $t) {
                $add(substr((string) $t->date, 0, 7), 'deposit_in', (float) $t->amount_in);
                $add(substr((string) $t->date, 0, 7), 'deposit_out', (float) $t->amount_out);
            }
            $names = $accounts->map(fn ($a) => NameNormalizer::normalize($a->name))->all();
            foreach (DB::table('bank_flow_balances')->where('year', $year)->get(['period', 'account', 'amount']) as $b) {
                if (in_array(NameNormalizer::normalize($b->account), $names, true) && isset($months[$b->period])) {
                    $months[$b->period]['deposit_balance'] = ($months[$b->period]['deposit_balance'] ?? 0.0) + (float) $b->amount;
                }
            }
        }

        foreach ($months as &$m) {
            foreach (['output', 'returns', 'input_einvoice', 'input_docs', 'deposit_in', 'deposit_out'] as $key) {
                $m[$key] = round($m[$key], 2);
            }
            $m['payable'] = round($m['output'] - $m['returns'] - $m['input_einvoice'], 2);
        }

        return $months;
    }

    /**
     * Müştərilər üzrə ƏDV: fakturalanmış ƏDV − qaytarılan ƏDV − depozitə daxil olan (VÖEN ilə tanınan) ödəniş.
     *
     * @return Collection<int, object{id: int, code: string, name: string, voen: string|null, vat: float, received: float, open: float}>
     */
    public function customers(?int $managerId = null): Collection
    {
        $returns = collect();
        foreach ($this->returnRows() as $r) {
            if ($r['customer_id']) {
                $returns[$r['customer_id']] = ($returns[$r['customer_id']] ?? 0.0) + $r['vat'];
            }
        }
        $received = $this->depositByTin('amount_in');
        $sales = [];
        foreach ($this->salesVat(null, true) as $r) {
            $sales[$r['customer_id']] = ($sales[$r['customer_id']] ?? 0.0) + $r['vat'];
        }

        return DB::table('customers')->whereIn('id', array_keys($sales))
            ->when($managerId !== null, fn ($q) => $q->where('manager_id', $managerId))->get(['id', 'code', 'name', 'voen'])
            ->map(function ($c) use ($sales, $returns, $received) {
                $c->vat = round(($sales[$c->id] ?? 0.0) - (float) ($returns[$c->id] ?? 0), 2);
                $c->received = round($received[(string) $c->voen] ?? 0.0, 2);
                $c->open = round($c->vat - $c->received, 2);

                return $c;
            })->filter(fn ($c) => abs($c->vat) > 0.005 || $c->received > 0.005)->sortByDesc('open')->values();
    }

    /**
     * Təchizatçılar üzrə ƏDV: daxil olan e-qaimələrin ƏDV-si − depozitdən onlara köçürülən (VÖEN ilə).
     *
     * @return Collection<int, object{tin: string, name: string|null, vat: float, paid: float, open: float}>
     */
    public function suppliers(): Collection
    {
        $paid = $this->depositByTin('amount_out');
        $rows = collect();
        foreach (DB::table('incoming_einvoices')->get(['sender_tin', 'sender_name', 'kind', 'status', 'vat_amount']) as $e) {
            if (preg_match('/ləğv|silin|rədd/iu', (string) $e->status)) {
                continue;
            }
            $row = $rows[$e->sender_tin] ?? (object) ['tin' => $e->sender_tin, 'name' => $e->sender_name, 'vat' => 0.0, 'paid' => 0.0, 'open' => 0.0];
            $row->vat += (preg_match('/qaytar/iu', (string) $e->kind) ? -1 : 1) * (float) $e->vat_amount;
            $rows[$e->sender_tin] = $row;
        }

        return $rows->map(function ($r) use ($paid) {
            $r->vat = round($r->vat, 2);
            $r->paid = round($paid[$r->tin] ?? 0.0, 2);
            $r->open = round($r->vat - $r->paid, 2);

            return $r;
        })->sortByDesc('open')->values();
    }

    /**
     * ƏDV depozit hesabları: «depozit» növlü bank hesabları.
     *
     * @return Collection<int, object>
     */
    public function depositAccounts(): Collection
    {
        return DB::table('bank_accounts')->where('kind', 'deposit')->orderBy('name')->get();
    }

    /**
     * Depozit hesabı əməliyyatlarının VÖEN üzrə cəmi (qarşı tərəf və ya təyinatda 10 rəqəmli VÖEN).
     *
     * @return array<string, float>
     */
    private function depositByTin(string $column): array
    {
        $accounts = $this->depositAccounts()->pluck('id');
        if ($accounts->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (DB::table('bank_transactions')->whereIn('bank_account_id', $accounts)->where($column, '>', 0)->get(['counterparty', 'description', $column]) as $t) {
            if (preg_match('/(?<!\d)(\d{10})(?!\d)/', $t->counterparty.' '.$t->description, $m)) {
                $out[$m[1]] = ($out[$m[1]] ?? 0.0) + (float) $t->{$column};
            }
        }

        return $out;
    }

    /**
     * Satışın ƏDV-si: fakturalanmış sətirdə yazılmış ƏDV, idxal olunmuş satışda (fakturasız) malın ƏDV rejimi ilə hesablanır.
     *
     * @return list<array{period: string, customer_id: int|null, vat: float}>
     */
    private function salesVat(?int $year, bool $byCustomer = false): array
    {
        $group = ['l.period', 'p.vat_mode', 'p.vat_rate', DB::raw('CASE WHEN l.sales_invoice_id IS NULL THEN 1 ELSE 0 END')];
        $rows = DB::table('sales_lines as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')->where('l.op_type', 'sale')
            ->when($year, fn ($q) => $q->where('l.period', 'like', $year.'-%'))
            ->when($byCustomer, fn ($q) => $q->whereNotNull('l.customer_id'))
            ->groupBy(array_merge($group, $byCustomer ? ['l.customer_id'] : []))
            ->select(array_merge(['l.period', 'p.vat_mode', 'p.vat_rate', DB::raw('CASE WHEN l.sales_invoice_id IS NULL THEN 1 ELSE 0 END AS imported'),
                DB::raw('SUM(l.net_amount) AS net'), DB::raw('SUM(l.vat_amount) AS vat')], $byCustomer ? ['l.customer_id'] : []))->get();

        return $rows->map(fn ($r) => [
            'period' => (string) $r->period, 'customer_id' => isset($r->customer_id) ? (int) $r->customer_id : null,
            'vat' => (int) $r->imported === 1 ? Vat::split((float) $r->net, $r->vat_mode, $r->vat_rate)['vat'] : (float) $r->vat,
        ])->all();
    }

    /**
     * @return array<string, float> dövr => qaytarma ƏDV-si
     */
    private function returnVat(int $year): array
    {
        $out = [];
        foreach ($this->returnRows($year) as $r) {
            $out[$r['period']] = ($out[$r['period']] ?? 0.0) + $r['vat'];
        }

        return $out;
    }

    /**
     * Müştəri qaytarmalarının ƏDV-si: qaytarma qaiməsi varsa onun sətrindən, idxal olunmuşlarda malın ƏDV rejimi ilə.
     *
     * @return list<array{period: string, customer_id: int|null, vat: float}>
     */
    private function returnRows(?int $year = null): array
    {
        $rows = DB::table('sales_returns as r')->leftJoin('products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('sales_return_document_lines as dl', function ($j) {
                $j->on('dl.sales_return_document_id', '=', 'r.sales_return_document_id')->on('dl.product_id', '=', 'r.product_id');
            })
            ->where('r.op_type', 'customer')->when($year, fn ($q) => $q->where('r.period', 'like', $year.'-%'))
            ->get(['r.period', 'r.customer_id', 'r.net_amount', 'r.sales_return_document_id', 'dl.net_amount as doc_net', 'dl.vat_amount as doc_vat', 'p.vat_mode', 'p.vat_rate']);

        return $rows->map(fn ($r) => [
            'period' => (string) $r->period, 'customer_id' => $r->customer_id ? (int) $r->customer_id : null,
            'vat' => $r->sales_return_document_id && (float) $r->doc_net > 0
                ? (float) $r->doc_vat * (float) $r->net_amount / (float) $r->doc_net
                : Vat::split((float) $r->net_amount, $r->vat_mode, $r->vat_rate)['vat'],
        ])->all();
    }
}
