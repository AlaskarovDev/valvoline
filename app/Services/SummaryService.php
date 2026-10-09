<?php

namespace App\Services;

use App\Support\BulkUpdater;
use Illuminate\Support\Facades\DB;

/**
 * Sifariş cəmləri, qaytarmaların sifarişə bağlanması və aylıq aqreqat cədvəl (monthly_sales_summary).
 */
class SummaryService
{
    public function linkReturnsToOrders(): void
    {
        $orderIds = [];
        foreach (DB::table('sales_orders')->get(['id', 'order_no']) as $order) {
            $orderIds[(string) $order->order_no] = $order->id;
        }

        // müştəri → mal → [tarix, sifariş] (tarixə görə artan)
        $history = [];
        $lines = DB::table('sales_lines')
            ->whereNotNull('customer_id')->whereNotNull('product_id')->whereNotNull('sales_order_id')
            ->where('op_type', 'sale')
            ->orderBy('date')->orderBy('id')
            ->get(['customer_id', 'product_id', 'date', 'sales_order_id']);
        foreach ($lines as $line) {
            $history[$line->customer_id][$line->product_id][] = [(string) $line->date, $line->sales_order_id];
        }

        $updates = [];
        foreach (DB::table('sales_returns')->get(['id', 'customer_id', 'product_id', 'date', 'note', 'return_no']) as $return) {
            $orderId = null;
            // 1) Qeyd-də sifariş nömrəsi yazılıbsa
            if (preg_match_all('/\b[\w\-\/]{3,}\b/u', (string) $return->note, $matches)) {
                foreach ($matches[0] as $token) {
                    if (isset($orderIds[$token])) {
                        $orderId = $orderIds[$token];
                        break;
                    }
                }
            }
            // 2) həmin müştərinin bu malı olan, qaytarmadan əvvəlki son sifarişi
            if ($orderId === null && $return->customer_id !== null && $return->product_id !== null) {
                foreach ($history[$return->customer_id][$return->product_id] ?? [] as [$date, $candidate]) {
                    if ($date > (string) $return->date) {
                        break;
                    }
                    $orderId = $candidate;
                }
            }
            $updates[$return->id] = ['sales_order_id' => $orderId];
        }
        BulkUpdater::update('sales_returns', $updates);
    }

    public function aggregateOrders(): void
    {
        $totals = DB::table('sales_lines')
            ->whereNotNull('sales_order_id')
            ->groupBy('sales_order_id')
            ->selectRaw("sales_order_id,
                COUNT(*) AS line_count,
                SUM(CASE WHEN op_type = 'sale' THEN net_amount ELSE 0 END) AS total_amount,
                SUM(COALESCE(cogs, 0)) AS total_cogs")
            ->get()->keyBy('sales_order_id');
        $returns = DB::table('sales_returns')
            ->whereNotNull('sales_order_id')->where('op_type', 'customer')
            ->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, SUM(net_amount) AS amount, SUM(COALESCE(cogs, 0)) AS cogs')
            ->get()->keyBy('sales_order_id');

        $updates = [];
        foreach (DB::table('sales_orders')->pluck('id') as $id) {
            $t = $totals[$id] ?? null;
            $r = $returns[$id] ?? null;
            $amount = (float) ($t->total_amount ?? 0);
            $cogs = (float) ($t->total_cogs ?? 0);
            $returnAmount = (float) ($r->amount ?? 0);
            $returnCogs = (float) ($r->cogs ?? 0);
            $updates[$id] = [
                'line_count' => (int) ($t->line_count ?? 0),
                'total_amount' => round($amount, 4),
                'total_cogs' => round($cogs, 4),
                'gross_profit' => round($amount - $cogs, 4),
                'return_amount' => round($returnAmount, 4),
                'return_cogs' => round($returnCogs, 4),
                'net_profit' => round(($amount - $cogs) - ($returnAmount - $returnCogs), 4),
            ];
        }
        BulkUpdater::update('sales_orders', $updates);

        // sifariş başlığı: müştəri bağlantısı sətirlərdən (pending həll olunubsa)
        $customers = DB::table('sales_lines')->whereNotNull('customer_id')->whereNotNull('sales_order_id')
            ->groupBy('sales_order_id')->selectRaw('sales_order_id, MAX(customer_id) AS customer_id')->pluck('customer_id', 'sales_order_id')->all();
        $headerUpdates = [];
        foreach (DB::table('sales_orders')->whereNull('customer_id')->pluck('id') as $id) {
            if (isset($customers[$id])) {
                $headerUpdates[$id] = ['customer_id' => $customers[$id]];
            }
        }
        BulkUpdater::update('sales_orders', $headerUpdates);
    }

    public function buildMonthlySummary(): void
    {
        DB::table('monthly_sales_summary')->delete();
        $rows = [];
        $sales = DB::table('sales_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->groupBy('l.period', 'l.op_type', 'l.product_id', 'l.customer_id', 'l.manager_id')
            ->selectRaw('l.period, l.op_type AS kind, l.product_id, l.customer_id, l.manager_id,
                SUM(l.qty) AS qty, SUM(l.qty * COALESCE(p.volume_l, 0)) AS liters,
                SUM(l.amount) AS gross_amount, SUM(l.net_amount) AS net_amount,
                SUM(COALESCE(l.cogs, 0)) AS cogs, COUNT(*) AS line_count')
            ->get();
        foreach ($sales as $row) {
            $rows[] = $this->summaryRow($row, $row->kind);
        }

        $returns = DB::table('sales_returns as r')
            ->leftJoin('products as p', 'p.id', '=', 'r.product_id')
            ->groupBy('r.period', 'r.op_type', 'r.product_id', 'r.customer_id', 'r.manager_id')
            ->selectRaw('r.period, r.op_type AS kind, r.product_id, r.customer_id, r.manager_id,
                SUM(r.qty) AS qty, SUM(r.qty * COALESCE(p.volume_l, 0)) AS liters,
                SUM(r.qty * r.price) AS gross_amount, SUM(r.net_amount) AS net_amount,
                SUM(COALESCE(r.cogs, 0)) AS cogs, COUNT(*) AS line_count')
            ->get();
        foreach ($returns as $row) {
            $rows[] = $this->summaryRow($row, 'return_'.$row->kind);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('monthly_sales_summary')->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryRow(object $row, string $kind): array
    {
        return [
            'period' => $row->period,
            'year' => (int) substr($row->period, 0, 4),
            'kind' => $kind,
            'product_id' => $row->product_id,
            'customer_id' => $row->customer_id,
            'manager_id' => $row->manager_id,
            'qty' => round((float) $row->qty, 4),
            'liters' => round((float) $row->liters, 4),
            'gross_amount' => round((float) $row->gross_amount, 4),
            'net_amount' => round((float) $row->net_amount, 4),
            'cogs' => round((float) $row->cogs, 4),
            'line_count' => (int) $row->line_count,
        ];
    }
}
