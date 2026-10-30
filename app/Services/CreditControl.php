<?php

namespace App\Services;

use App\Models\Customer;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;

/**
 * Kredit nəzarəti: müştərinin riski = cari borc + hələ fakturalanmamış açıq sifarişlər + yeni sifariş.
 *  - bloklu müştəriyə sifariş yazılmır;
 *  - kredit limiti varsa risk limiti keçə bilməz (admin «limiti aş» seçərək keçə bilər);
 *  - gecikmiş borc (ödəniş tarixi keçmiş) xəbərdarlıqdır.
 */
class CreditControl
{
    /**
     * @return array{status: string, block_reason: string|null, limit: float|null, debt: float, open_orders: float, exposure: float, available: float|null, overdue: float, overdue_days: int, payment_days: int}
     */
    public function snapshot(Customer $customer, ?int $exceptOrderId = null): array
    {
        $balance = DB::table('customer_balances')->where('customer_id', $customer->id)->first(['balance', 'overdue_amount', 'overdue_days']);
        $openOrders = $this->openOrders([$customer->id], $exceptOrderId)[$customer->id] ?? 0.0;
        $debt = (float) ($balance->balance ?? 0);
        $limit = $customer->credit_limit !== null ? (float) $customer->credit_limit : null;
        // avans (mənfi balans) riski azaldır
        $exposure = round($debt + $openOrders, 2);

        return [
            'status' => $customer->status ?? 'active', 'block_reason' => $customer->block_reason,
            'limit' => $limit, 'debt' => round($debt, 2), 'open_orders' => round($openOrders, 2), 'exposure' => $exposure,
            'available' => $limit !== null ? round($limit - $exposure, 2) : null,
            'overdue' => round((float) ($balance->overdue_amount ?? 0), 2), 'overdue_days' => (int) ($balance->overdue_days ?? 0),
            'payment_days' => (int) ($customer->payment_days ?? 0),
        ];
    }

    /**
     * Sifarişi saxlamağa mane olan səbəblər (boşdursa — icazə var).
     *
     * @return list<string>
     */
    public function violations(Customer $customer, float $orderAmount, ?int $exceptOrderId = null, bool $override = false): array
    {
        $s = $this->snapshot($customer, $exceptOrderId);
        $out = [];
        if ($s['status'] === 'blocked') {
            $out[] = __('Müştəri bloklanıb: :reason', ['reason' => $s['block_reason'] ?: '—']);
        }
        if (! $override && $s['limit'] !== null && $s['exposure'] + $orderAmount > $s['limit'] + 0.004) {
            $out[] = __('Kredit limiti aşılır: limit :limit, borc + açıq sifarişlər :exposure, bu sifariş :amount (sərbəst: :available).', [
                'limit' => Fmt::money($s['limit']), 'exposure' => Fmt::money($s['exposure']), 'amount' => Fmt::money($orderAmount), 'available' => Fmt::money(max($s['available'], 0)),
            ]);
        }

        return $out;
    }

    /**
     * Fakturalanmamış (rezervdə və ya təhvil verilmiş) əl ilə sifarişlərin məbləği: customer_id => məbləğ.
     *
     * @param  list<int>|null  $customerIds
     * @return array<int, float>
     */
    public function openOrders(?array $customerIds = null, ?int $exceptOrderId = null): array
    {
        return DB::table('sales_order_items as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->where('o.source', 'manual')->whereIn('o.status', ['open', 'shipped'])->whereNull('o.cancelled_at')->where('i.op_type', 'sale')
            ->when($customerIds !== null, fn ($q) => $q->whereIn('o.customer_id', $customerIds ?: [0]))
            ->when($exceptOrderId, fn ($q) => $q->where('o.id', '<>', $exceptOrderId))
            ->groupBy('o.customer_id')->selectRaw('o.customer_id, SUM(i.net_amount) AS amount')
            ->pluck('amount', 'customer_id')->map(fn ($v) => (float) $v)->all();
    }
}
