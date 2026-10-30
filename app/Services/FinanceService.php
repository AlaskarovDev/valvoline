<?php

namespace App\Services;

use App\Models\FinanceDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kassa və bank sənədləri, qalıqlar və hərəkət jurnalı.
 *  - Açılış sənədi həmin tarixə ilkin qalığı qoyur: tarixdən əvvəlki hərəkətlər nəzərə alınmır.
 *  - Kassa mədaxili (müştəri ödənişi) ödəniş kimi yazılır → müştəri borcu azalır və pul nümayəndənin kassasında oturur.
 *  - İmport edilmiş nağd ödənişlər nümayəndəsinin kassasına (yoxdursa əsas kassaya) düşür.
 *  - Banklar arası transfer iki bank əməliyyatı yaradır (çıxış / mədaxil, valyuta fərqlidirsə məzənnə ilə).
 */
class FinanceService
{
    /**
     * Kassanın hərəkətləri (tarix sırası ilə), açılışdan sonrakılar.
     *
     * @return Collection<int, object>
     */
    public function cashLedger(int $registerId, ?Carbon $until = null): Collection
    {
        $until ??= now()->endOfDay();
        $register = DB::table('cash_registers')->find($registerId);
        $mainId = (int) DB::table('cash_registers')->where('type', 'main')->value('id');
        $opening = DB::table('finance_documents')->where('type', 'cash_opening')->where('cash_register_id', $registerId)->whereNull('cancelled_at')
            ->whereDate('date', '<=', $until->toDateString())->orderByDesc('date')->orderByDesc('id')->first();
        $from = $opening ? substr((string) $opening->date, 0, 10) : null;
        $rows = collect();
        if ($opening) {
            $rows->push((object) ['date' => $from, 'kind' => 'opening', 'doc_no' => $opening->doc_no, 'doc_id' => $opening->id, 'text' => __('Açılış qalığı'), 'in' => (float) $opening->amount, 'out' => 0.0, 'opening' => true]);
        }
        $after = fn ($q, string $col = 'date') => $q->when($from, fn ($w) => $w->whereDate($col, '>=', $from))->whereDate($col, '<=', $until->toDateString());

        $docs = $after(DB::table('finance_documents as d')->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('cash_registers as r1', 'r1.id', '=', 'd.cash_register_id')->leftJoin('cash_registers as r2', 'r2.id', '=', 'd.to_cash_register_id')
            ->whereIn('d.type', ['cash_receipt', 'cash_transfer'])->whereNull('d.cancelled_at')
            ->where(fn ($q) => $q->where('d.cash_register_id', $registerId)->orWhere('d.to_cash_register_id', $registerId)), 'd.date')
            ->get(['d.*', 'c.name as customer_name', 'r1.code as from_code', 'r2.code as to_code']);
        foreach ($docs as $d) {
            if ($d->type === 'cash_receipt') {
                $rows->push((object) ['date' => substr((string) $d->date, 0, 10), 'kind' => 'receipt', 'doc_no' => $d->doc_no, 'doc_id' => $d->id, 'text' => __('Müştəri ödənişi').': '.$d->customer_name, 'in' => (float) $d->amount, 'out' => 0.0]);
            } elseif ((int) $d->cash_register_id === $registerId) {
                $rows->push((object) ['date' => substr((string) $d->date, 0, 10), 'kind' => 'transfer_out', 'doc_no' => $d->doc_no, 'doc_id' => $d->id, 'text' => __('Transfer → :code', ['code' => $d->to_code]), 'in' => 0.0, 'out' => (float) $d->amount]);
            } else {
                $rows->push((object) ['date' => substr((string) $d->date, 0, 10), 'kind' => 'transfer_in', 'doc_no' => $d->doc_no, 'doc_id' => $d->id, 'text' => __('Transfer ← :code', ['code' => $d->from_code]), 'in' => (float) ($d->to_amount ?? $d->amount), 'out' => 0.0]);
            }
        }

        // import edilmiş nağd ödənişlər: ödənişin kassası → nümayəndənin kassası → əsas kassa.
        // Açılış sənədi yoxdursa kassa yaradıldığı gündən başlayır (köhnə tarixçə kassaya yığılmır).
        $since = $from ?? substr((string) $register->created_at, 0, 10);
        $payments = DB::table('payments as p')->whereDate('p.date', '>=', $since)->whereDate('p.date', '<=', $until->toDateString())
            ->tap(fn ($q) => $q->leftJoin('cash_registers as r', 'r.manager_id', '=', 'p.manager_id')
                ->whereNull('p.finance_document_id')->where('p.cash', '>', 0)
                ->whereRaw('COALESCE(p.cash_register_id, r.id, ?) = ?', [$mainId, $registerId]))
            ->get(['p.date', 'p.cash', 'p.customer_name', 'p.manager_name']);
        foreach ($payments as $p) {
            $rows->push((object) ['date' => substr((string) $p->date, 0, 10), 'kind' => 'import', 'doc_no' => null, 'doc_id' => null, 'text' => __('Ödəniş (import)').': '.$p->customer_name, 'in' => (float) $p->cash, 'out' => 0.0]);
        }

        $sorted = $rows->sortBy(fn ($r) => [$r->date, ($r->opening ?? false) ? 0 : 1])->values();
        $running = 0.0;
        foreach ($sorted as $r) {
            $running += $r->in - $r->out;
            $r->balance = round($running, 2);
        }

        return $sorted;
    }

    /**
     * @return array<int, float>
     */
    public function cashBalances(): array
    {
        return DB::table('cash_registers')->pluck('id')->mapWithKeys(fn ($id) => [$id => round((float) optional($this->cashLedger((int) $id)->last())->balance, 2)])->all();
    }

    /**
     * Bank hesabının qalığı: açılış + sonrakı bank əməliyyatları.
     *
     * @return array{balance: float, opening: object|null, last_date: string|null}
     */
    public function bankBalance(int $accountId, ?Carbon $until = null): array
    {
        $until ??= now()->endOfDay();
        $opening = DB::table('finance_documents')->where('type', 'bank_opening')->where('bank_account_id', $accountId)->whereNull('cancelled_at')
            ->whereDate('date', '<=', $until->toDateString())->orderByDesc('date')->orderByDesc('id')->first();
        $tx = DB::table('bank_transactions')->where('bank_account_id', $accountId)
            ->when($opening, fn ($q) => $q->whereDate('date', '>=', substr((string) $opening->date, 0, 10)))->whereDate('date', '<=', $until->toDateString())
            ->selectRaw('SUM(amount_in) - SUM(amount_out) AS net, MAX(date) AS last')->first();

        return ['balance' => round((float) ($opening->amount ?? 0) + (float) ($tx->net ?? 0), 2), 'opening' => $opening, 'last_date' => $tx->last ?? null];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $type, array $data, ?int $userId): FinanceDocument
    {
        PeriodLock::assertOpen($data['date'] ?? null);

        return DB::transaction(function () use ($type, $data, $userId) {
            $doc = FinanceDocument::query()->create(array_merge([
                'doc_no' => app(NumberingService::class)->next('fin_'.$type, ['date' => $data['date'] ?? null, 'workplace_id' => $data['workplace_id'] ?? null, 'manager_id' => $data['manager_id'] ?? null]),
                'type' => $type, 'created_by' => $userId, 'currency' => $data['currency'] ?? 'AZN',
            ], array_intersect_key($data, array_flip([
                'date', 'workplace_id', 'cash_register_id', 'to_cash_register_id', 'bank_account_id', 'to_bank_account_id',
                'amount', 'to_amount', 'rate', 'customer_id', 'manager_id', 'note',
            ]))));
            $now = now();
            if ($type === 'cash_receipt') {
                // müştəri ödənişi → borc azalır (payments), pul kassada
                $customer = DB::table('customers')->find($doc->customer_id);
                $manager = $doc->manager_id ? DB::table('managers')->find($doc->manager_id) : null;
                $paymentId = DB::table('payments')->insertGetId([
                    'row_no' => (int) DB::table('payments')->max('row_no') + 1, 'date' => $doc->date->toDateString(), 'period' => $doc->date->format('Y-m'),
                    'customer_id' => $customer->id, 'customer_code' => $customer->code, 'customer_name' => $customer->name, 'original_customer_name' => $customer->name,
                    'manager_id' => $doc->manager_id, 'manager_name' => $manager?->name, 'cash' => $doc->amount, 'bank' => 0, 'total' => $doc->amount,
                    'received_by' => $manager?->name, 'note' => $doc->doc_no.($doc->note ? ' — '.$doc->note : ''), 'status' => 'ok',
                    'cash_register_id' => $doc->cash_register_id, 'finance_document_id' => $doc->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $doc->forceFill(['payment_id' => $paymentId])->save();
            }
            if ($type === 'bank_transfer') {
                $accounts = DB::table('bank_accounts')->whereIn('id', [$doc->bank_account_id, $doc->to_bank_account_id])->pluck('name', 'id');
                $common = ['date' => $doc->date->toDateString(), 'period' => $doc->date->format('Y-m'), 'doc_no' => $doc->doc_no, 'source' => 'transfer',
                    'finance_document_id' => $doc->id, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now];
                DB::table('bank_transactions')->insert($common + ['bank_account_id' => $doc->bank_account_id, 'amount_in' => 0, 'amount_out' => $doc->amount,
                    'counterparty' => $accounts[$doc->to_bank_account_id] ?? null, 'description' => __('Banklar arası transfer').' '.$doc->doc_no.($doc->note ? ' — '.$doc->note : '')]);
                DB::table('bank_transactions')->insert($common + ['bank_account_id' => $doc->to_bank_account_id, 'amount_in' => $doc->to_amount ?? $doc->amount, 'amount_out' => 0,
                    'counterparty' => $accounts[$doc->bank_account_id] ?? null, 'description' => __('Banklar arası transfer').' '.$doc->doc_no.($doc->note ? ' — '.$doc->note : '')]);
            }

            return $doc;
        });
    }

    /**
     * Sənədi ləğv edir: sənəd qalır («ləğv edilib»), yaratdığı ödəniş və bank əməliyyatları götürülür.
     */
    public function cancel(FinanceDocument $doc, ?int $userId = null, ?string $reason = null): void
    {
        $doc->assertNotCancelled();
        PeriodLock::assertOpen($doc->date);
        DB::transaction(function () use ($doc, $userId, $reason) {
            DB::table('payments')->where('finance_document_id', $doc->id)->delete();
            DB::table('bank_transactions')->where('finance_document_id', $doc->id)->delete();
            $doc->markCancelled($userId, $reason);
        });
    }

    /**
     * Transferdə mənbə kassada kifayət qədər pul.
     */
    public function assertCashAvailable(int $registerId, float $amount, Carbon $date): void
    {
        $balance = (float) optional($this->cashLedger($registerId, $date->copy()->endOfDay())->last())->balance;
        if ($amount > $balance + 0.004) {
            throw new RuntimeException(__('Kassada kifayət qədər pul yoxdur: qalıq :b.', ['b' => number_format($balance, 2, ',', ' ')]));
        }
    }
}
