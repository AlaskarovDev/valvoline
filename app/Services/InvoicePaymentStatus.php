<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Fakturanın ödəniş vəziyyəti: müştərinin ödənişləri və qaytarmaları FIFO ilə ən köhnə borclara bağlanır
 * (borc yaşı ilə eyni qayda). Nəticə faktura üzrə: məbləğ, bağlanan və qalan hissə.
 */
class InvoicePaymentStatus
{
    /**
     * @param  list<int>  $customerIds
     * @return array<int, array{amount: float, paid: float, open: float, status: string}> sales_invoice_id => vəziyyət
     */
    public function forCustomers(array $customerIds): array
    {
        if (! $customerIds) {
            return [];
        }
        $charges = DB::table('sales_lines')->whereIn('customer_id', $customerIds)->where('op_type', 'sale')
            ->groupBy('customer_id', 'date', 'order_no', 'sales_invoice_id')->orderBy('date')->orderBy('order_no')
            ->selectRaw('customer_id, date, order_no, sales_invoice_id, SUM(net_amount) AS amount')->get()->groupBy('customer_id');
        $payments = DB::table('payments')->whereIn('customer_id', $customerIds)->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(CASE WHEN total > 0 THEN total ELSE 0 END) AS amount')->pluck('amount', 'customer_id');
        $returns = DB::table('sales_returns')->whereIn('customer_id', $customerIds)->where('op_type', 'customer')->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(net_amount) AS amount')->pluck('amount', 'customer_id');

        $out = [];
        foreach ($customerIds as $customerId) {
            $credit = (float) ($payments[$customerId] ?? 0) + (float) ($returns[$customerId] ?? 0);
            foreach ($charges[$customerId] ?? [] as $charge) {
                $amount = (float) $charge->amount;
                $paid = min($credit, $amount);
                $credit -= $paid;
                if ($charge->sales_invoice_id) {
                    $open = round($amount - $paid, 2);
                    $out[(int) $charge->sales_invoice_id] = ['amount' => round($amount, 2), 'paid' => round($paid, 2), 'open' => $open,
                        'status' => $open <= 0.004 ? 'paid' : ($paid > 0.004 ? 'partial' : 'unpaid')];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return ['paid' => __('Ödənilib'), 'partial' => __('Qismən ödənilib'), 'unpaid' => __('Ödənilməyib')];
    }
}
