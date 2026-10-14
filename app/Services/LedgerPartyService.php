<?php

namespace App\Services;

use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mühasibat dövriyyə vərəqindəki (60, 61, 62, 64 …) kontragent / sənəd üçün detal:
 * mühasibatdakı bütün sənədləri, bank hərəkətləri (Cash Flow) və sistemdəki qarşılığı —
 * alıcı (62) üçün satış sifarişləri, malsatan (60) üçün idxal partiyaları, sənəd tarixinə yaxın.
 */
class LedgerPartyService
{
    /** Sənəd tarixindən bu qədər gün əvvəl / sonra sistem qeydləri axtarılır. */
    private const WINDOW_DAYS = ['62' => 3, 'default' => 10];

    /**
     * @return array{documents: Collection<int, object>, bank: Collection<int, object>, customer: object|null, matches: Collection<int, object>, kind: string, focus: Collection<int, object>, settlement: array<string, mixed>|null}
     */
    public function party(string $code, string $counterparty, ?string $document): array
    {
        $documents = DB::table('acc_ledger_lines')->where('legacy_code', $code)->where('counterparty', $counterparty)->whereNotNull('document')
            ->orderBy('year')->orderBy('row_no')->get()
            ->map(function ($d) {
                $d->doc_date = self::documentDate((string) $d->document);
                $d->amount = (float) $d->debit - (float) $d->credit;

                return $d;
            });
        $focus = $document === null ? $documents : $documents->where('document', $document)->values();

        $key = NameNormalizer::normalize($counterparty);
        $bank = DB::table('bank_flow_lines')->whereIn('section', ['income', 'payment'])->where('amount', '!=', 0)->get()
            ->filter(fn ($f) => self::sameParty($key, (string) $f->normalized))->sortBy('period')->values();

        $kind = str_starts_with($code, '62') ? 'customer' : (str_starts_with($code, '60') ? 'supplier' : 'other');
        $customer = null;
        $matches = collect();
        $window = self::WINDOW_DAYS[$code] ?? self::WINDOW_DAYS['default'];
        if ($kind === 'customer') {
            [$customer] = ReconciliationService::bestCustomer($counterparty, DB::table('customers')->get(['id', 'name', 'other_names']));
            if ($customer !== null) {
                $matches = $focus->filter(fn ($d) => $d->doc_date !== null)->flatMap(fn ($d) => DB::table('sales_orders')->where('customer_id', $customer->id)
                    ->whereBetween('date', [$d->doc_date->copy()->subDays($window)->toDateString(), $d->doc_date->copy()->addDays($window)->toDateString()])
                    ->orderBy('date')->get(['id', 'order_no', 'date', 'total_amount', 'return_amount'])
                    ->map(fn ($o) => (object) ['document' => $d->document, 'doc_amount' => $d->amount, 'id' => $o->id, 'ref' => $o->order_no, 'date' => $o->date,
                        'amount' => (float) $o->total_amount, 'exact' => abs((float) $o->total_amount - abs($d->amount)) < 1]))->values();
            }
        } elseif ($kind === 'supplier') {
            $matches = $focus->filter(fn ($d) => $d->doc_date !== null)->flatMap(fn ($d) => DB::table('purchase_batches')
                ->whereBetween('date', [$d->doc_date->copy()->subDays($window)->toDateString(), $d->doc_date->copy()->addDays($window)->toDateString()])
                ->orderBy('date')->get(['id', 'batch_no', 'date', 'name', 'total_cost'])
                ->map(fn ($b) => (object) ['document' => $d->document, 'doc_amount' => $d->amount, 'id' => $b->id, 'ref' => trim('№'.$b->batch_no.' '.($b->name ?? '')), 'date' => $b->date,
                    'amount' => (float) $b->total_cost, 'exact' => abs((float) $b->total_cost - abs($d->amount)) < 1]))->values();
        }

        return ['documents' => $documents, 'bank' => $bank, 'customer' => $customer, 'matches' => $matches, 'kind' => $kind, 'focus' => $focus,
            'settlement' => $this->settlement($kind, $focus, $bank, $matches)];
    }

    /**
     * Dövriyyə vərəqindəki məbləğ sənədin dəyəri deyil, il sonuna QALIQ borcdur. Sənədin tam dəyəri:
     * sənəd ayından il sonuna qədər bankdan ödənilən (alıcıda — daxil olan) + qalıq; sistemdəki qarşılıqla müqayisə.
     *
     * @param  Collection<int, object>  $focus
     * @param  Collection<int, object>  $bank
     * @param  Collection<int, object>  $matches
     * @return array{paid: float, bank_period: float, remaining: float, total: float, system: float|null, system_ref: string|null, diff: float|null, from: string, to: string}|null
     */
    private function settlement(string $kind, Collection $focus, Collection $bank, Collection $matches): ?array
    {
        $doc = $focus->count() === 1 ? $focus->first() : null;
        if ($doc === null || $doc->doc_date === null || $kind === 'other') {
            return null;
        }
        $from = $doc->doc_date->format('Y-m');
        $to = $doc->year.'-12';
        $paid = (float) $bank->where('section', $kind === 'customer' ? 'income' : 'payment')
            ->filter(fn ($f) => $f->period >= $from && $f->period <= $to)->sum('amount');
        $remaining = abs((float) $doc->amount);
        // iki ehtimal: sənəd hələ ödənilməyib (qalıq = tam dəyər) və ya ödənişlər bu sənədə aiddir (ödənilən + qalıq).
        // Sistemdəki qeydə hansı yaxındırsa o seçilir; qeyd yoxdursa malsatanda ödənişlər, alıcıda ödənilməmiş sayılır
        // (alıcının daxilolmaları çox vaxt köhnə sənədləri bağlayır).
        $candidates = $paid > 0.005 ? [0.0, $paid] : [0.0];
        $best = null;
        foreach ($candidates as $assumedPaid) {
            $total = $assumedPaid + $remaining;
            $system = $matches->sortBy(fn ($m) => abs($m->amount - $total))->first();
            $diff = $system ? abs($system->amount - $total) : INF;
            if ($best === null || $diff < $best['score']) {
                $best = ['score' => $diff, 'paid' => $assumedPaid, 'system' => $system];
            }
        }
        if ($best['system'] === null) {
            $best['paid'] = $kind === 'supplier' ? $paid : 0.0;
        }
        $total = $best['paid'] + $remaining;
        $system = $best['system'];

        return ['paid' => $best['paid'], 'bank_period' => $paid, 'remaining' => $remaining, 'total' => $total, 'from' => $from, 'to' => $to,
            'system' => $system?->amount, 'system_ref' => $system?->ref, 'diff' => $system ? $system->amount - $total : null];
    }

    /**
     * «Mallarin daxil olmasi 000002 (29.04.26)» → 2026-04-29.
     */
    public static function documentDate(string $document): ?Carbon
    {
        if (! preg_match('/\((\d{2})\.(\d{2})\.(\d{2,4})\)/', $document, $m)) {
            return null;
        }
        $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

        return checkdate((int) $m[2], (int) $m[1], $year) ? Carbon::create($year, (int) $m[2], (int) $m[1]) : null;
    }

    /**
     * Eyni kontragentdir: adlar eynidir və ya biri digərini əhatə edir («electro motors baku» ↔ «electro motors baku mmc»).
     */
    private static function sameParty(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        $strip = fn (string $v) => trim(preg_replace('/\b(mmc|asc|qsc|ooo|ltd|llc)\b/u', '', $v) ?? $v);
        if ($strip($a) === '' || $strip($b) === '') {
            return false;
        }

        return $strip($a) === $strip($b) || str_contains($b, $strip($a)) || str_contains($a, $strip($b));
    }
}
