<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Müştəri balansı = Σ satış yekun − Σ qaytarma yekun − Σ ödəniş.
 * Müsbət = müştəri bizə borcludur; mənfi = avans / artıq ödəniş.
 *
 * Borc yaşı (aging): ödənişlər və qaytarmalar FIFO ilə ən köhnə sifarişlərə bağlanır,
 * qalan borc sifariş tarixinə görə 0–30, 31–60, 61–90, 90+ gün qruplarına bölünür.
 */
class CustomerBalanceService
{
    public function rebuild(?Carbon $asOf = null): void
    {
        $asOf ??= $this->defaultAsOf();
        DB::table('customer_balances')->delete();

        $sales = $this->sumBy('sales_lines', "op_type = 'sale'", 'net_amount');
        $returns = $this->sumBy('sales_returns', "op_type = 'customer'", 'net_amount');
        $payments = $this->sumBy('payments', '1 = 1', 'total');
        $lastPayment = DB::table('payments')->whereNotNull('customer_id')->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(date) AS d')->pluck('d', 'customer_id')->all();
        $saleDates = DB::table('sales_lines')->whereNotNull('customer_id')->where('op_type', 'sale')->groupBy('customer_id')
            ->selectRaw('customer_id, MIN(date) AS first_d, MAX(date) AS last_d, COUNT(DISTINCT sales_order_id) AS orders')
            ->get()->keyBy('customer_id');

        $rows = [];
        foreach (DB::table('customers')->pluck('id') as $customerId) {
            $aging = $this->aging($customerId, $asOf);
            $s = (float) ($sales[$customerId] ?? 0);
            $r = (float) ($returns[$customerId] ?? 0);
            $p = (float) ($payments[$customerId] ?? 0);
            $dates = $saleDates[$customerId] ?? null;
            $rows[] = [
                'customer_id' => $customerId,
                'sales_total' => round($s, 4),
                'returns_total' => round($r, 4),
                'payments_total' => round($p, 4),
                'balance' => round($s - $r - $p, 4),
                'aging_0_30' => $aging['0_30'],
                'aging_31_60' => $aging['31_60'],
                'aging_61_90' => $aging['61_90'],
                'aging_90_plus' => $aging['90_plus'],
                'oldest_unpaid_date' => $aging['oldest'],
                'first_sale_date' => $dates->first_d ?? null,
                'last_sale_date' => $dates->last_d ?? null,
                'last_payment_date' => $lastPayment[$customerId] ?? null,
                'order_count' => (int) ($dates->orders ?? 0),
                'as_of' => $asOf->toDateString(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customer_balances')->insert($chunk);
        }
    }

    /**
     * Borclar üçün baza tarixi: bu gün (məlumat daha irəlidədirsə — son əməliyyat tarixi).
     */
    public function defaultAsOf(): Carbon
    {
        $last = max((string) DB::table('sales_lines')->max('date'), (string) DB::table('payments')->max('date'));

        return $last !== '' && $last > now()->toDateString() ? Carbon::parse($last) : now()->startOfDay();
    }

    /**
     * @return array{'0_30': float, '31_60': float, '61_90': float, '90_plus': float, oldest: string|null}
     */
    public function aging(int $customerId, Carbon $asOf): array
    {
        // borc yaradanlar: satış sifarişləri (tarix üzrə) + müştəriyə geri ödənişlər (mənfi ödəniş)
        $charges = DB::table('sales_lines')->where('customer_id', $customerId)->where('op_type', 'sale')
            ->where('date', '<=', $asOf->toDateString())
            ->groupBy('date', 'order_no')->orderBy('date')->orderBy('order_no')
            ->selectRaw('date, order_no, SUM(net_amount) AS amount')->get()
            ->map(fn ($r) => ['date' => (string) $r->date, 'amount' => (float) $r->amount])->all();
        $credits = 0.0;
        foreach (DB::table('payments')->where('customer_id', $customerId)->where('date', '<=', $asOf->toDateString())->get(['date', 'total']) as $payment) {
            if ((float) $payment->total < 0) {
                $charges[] = ['date' => (string) $payment->date, 'amount' => -(float) $payment->total];
            } else {
                $credits += (float) $payment->total;
            }
        }
        $credits += (float) DB::table('sales_returns')->where('customer_id', $customerId)->where('op_type', 'customer')
            ->where('date', '<=', $asOf->toDateString())->sum('net_amount');
        usort($charges, fn ($a, $b) => $a['date'] <=> $b['date']);

        $buckets = ['0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0, 'oldest' => null];
        foreach ($charges as $charge) {
            $open = $charge['amount'];
            if ($credits > 0) {
                $applied = min($credits, $open);
                $open -= $applied;
                $credits -= $applied;
            }
            if ($open <= 0.004) {
                continue;
            }
            $buckets['oldest'] ??= $charge['date'];
            $days = Carbon::parse($charge['date'])->diffInDays($asOf);
            $key = match (true) {
                $days <= 30 => '0_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $buckets[$key] += $open;
        }
        foreach (['0_30', '31_60', '61_90', '90_plus'] as $key) {
            $buckets[$key] = round($buckets[$key], 4);
        }

        return $buckets;
    }

    /**
     * Akt-sverka: tarix üzrə satış / qaytarma / ödəniş və qalıq sütunu.
     *
     * @return array{opening: float, rows: list<array{date: string, type: string, doc: string|null, description: string|null, debit: float, credit: float, balance: float}>, closing: float}
     */
    public function statement(int $customerId, ?string $from = null, ?string $to = null): array
    {
        $entries = [];
        $orders = DB::table('sales_lines')->where('customer_id', $customerId)->where('op_type', 'sale')
            ->groupBy('date', 'order_no', 'invoice_no')
            ->selectRaw('date, order_no, invoice_no, SUM(net_amount) AS amount, COUNT(*) AS line_cnt')->get();
        foreach ($orders as $o) {
            $entries[] = ['date' => (string) $o->date, 'order' => 1, 'type' => 'Satış', 'doc' => $o->order_no,
                'description' => ($o->invoice_no ? "Qaimə {$o->invoice_no} · " : '').$o->line_cnt.' sətir', 'debit' => (float) $o->amount, 'credit' => 0.0];
        }
        $returns = DB::table('sales_returns')->where('customer_id', $customerId)->where('op_type', 'customer')
            ->groupBy('date', 'return_no')
            ->selectRaw('date, return_no, SUM(net_amount) AS amount, COUNT(*) AS line_cnt')->get();
        foreach ($returns as $r) {
            $entries[] = ['date' => (string) $r->date, 'order' => 2, 'type' => 'Qaytarma', 'doc' => $r->return_no,
                'description' => $r->line_cnt.' sətir', 'debit' => 0.0, 'credit' => (float) $r->amount];
        }
        foreach (DB::table('payments')->where('customer_id', $customerId)->orderBy('date')->get() as $p) {
            $total = (float) $p->total;
            $how = trim(((float) $p->cash != 0 ? 'nağd' : '').((float) $p->cash != 0 && (float) $p->bank != 0 ? ' + ' : '').((float) $p->bank != 0 ? 'bank' : ''));
            $entries[] = ['date' => (string) $p->date, 'order' => 3, 'type' => $total < 0 ? 'Geri ödəniş' : 'Ödəniş', 'doc' => null,
                'description' => trim($how.($p->note ? ' · '.$p->note : '')), 'debit' => $total < 0 ? -$total : 0.0, 'credit' => $total > 0 ? $total : 0.0];
        }
        usort($entries, fn ($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

        $opening = 0.0;
        $balance = 0.0;
        $rows = [];
        foreach ($entries as $entry) {
            $balance += $entry['debit'] - $entry['credit'];
            if ($from !== null && $entry['date'] < $from) {
                $opening = $balance;

                continue;
            }
            if ($to !== null && $entry['date'] > $to) {
                break;
            }
            unset($entry['order']);
            $rows[] = $entry + ['balance' => $balance];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $rows === [] ? $opening : end($rows)['balance']];
    }

    /**
     * @return array<int, float>
     */
    private function sumBy(string $table, string $where, string $column): array
    {
        return DB::table($table)->whereNotNull('customer_id')->whereRaw($where)->groupBy('customer_id')
            ->selectRaw("customer_id, SUM({$column}) AS s")->pluck('s', 'customer_id')->map(fn ($v) => (float) $v)->all();
    }
}
