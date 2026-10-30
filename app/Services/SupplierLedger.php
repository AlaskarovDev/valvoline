<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Təchizatçı hesabı (təchizatçının valyutasında): mədaxil borcu artırır, qaytarma və ödəniş azaldır.
 * Müsbət balans = biz təchizatçıya borcluyuq (kreditor borcu).
 */
class SupplierLedger
{
    /**
     * @return array<int, array{received: float, returned: float, paid: float, balance: float, overdue: float}>
     */
    public function balances(?array $supplierIds = null): array
    {
        $docs = DB::table('purchase_documents')->whereNull('cancelled_at')
            ->when($supplierIds !== null, fn ($q) => $q->whereIn('supplier_id', $supplierIds ?: [0]))
            ->groupBy('supplier_id', 'type')->selectRaw('supplier_id, type, SUM(total_amount) AS amount')->get();
        $overdue = DB::table('purchase_documents')->whereNull('cancelled_at')->where('type', 'receipt')->where('due_date', '<', now()->toDateString())
            ->when($supplierIds !== null, fn ($q) => $q->whereIn('supplier_id', $supplierIds ?: [0]))
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total_amount) AS amount')->pluck('amount', 'supplier_id');
        $paid = DB::table('finance_documents')->whereNull('cancelled_at')->where('type', 'supplier_payment')
            ->when($supplierIds !== null, fn ($q) => $q->whereIn('supplier_id', $supplierIds ?: [0]))
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(COALESCE(to_amount, amount)) AS amount')->pluck('amount', 'supplier_id');

        $out = [];
        foreach ($docs as $d) {
            $out[(int) $d->supplier_id] ??= ['received' => 0.0, 'returned' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'overdue' => 0.0];
            $out[(int) $d->supplier_id][$d->type === 'receipt' ? 'received' : 'returned'] += (float) $d->amount;
        }
        foreach ($paid as $id => $amount) {
            $out[(int) $id] ??= ['received' => 0.0, 'returned' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'overdue' => 0.0];
            $out[(int) $id]['paid'] = (float) $amount;
        }
        foreach ($out as $id => &$b) {
            $b['balance'] = round($b['received'] - $b['returned'] - $b['paid'], 2);
            // gecikmiş: ödəniş tarixi keçmiş mədaxillərdən ödənilməyən hissə (ödəniş və qaytarma əvvəlcə köhnələri bağlayır)
            $b['overdue'] = round(max(0.0, min($b['balance'], (float) ($overdue[$id] ?? 0) - $b['returned'] - $b['paid'])), 2);
        }

        return $out;
    }

    /**
     * Valyutalı təchizatçıda məzənnə fərqi (AZN):
     *  - mədaxillər sənəd kursu ilə qiymətləndirilir → orta kurs;
     *  - reallaşmış fərq: ödənişdə verilən AZN − silinən valyuta borcu × orta kurs (müsbət = zərər, çox ödədik);
     *  - reallaşmamış fərq: açıq borc × (bugünkü Mərkəzi Bank kursu − orta kurs) (müsbət = borcumuz AZN-lə artıb).
     *
     * @return array{avg_rate: float, current_rate: float|null, realized: float, unrealized: float|null, paid_azn: float}|null
     */
    public function fx(Supplier $supplier): ?array
    {
        if ($supplier->currency === 'AZN') {
            return null;
        }
        $docs = DB::table('purchase_documents')->whereNull('cancelled_at')->where('supplier_id', $supplier->id)->where('type', 'receipt')
            ->selectRaw('SUM(total_amount) AS amount, SUM(total_amount * rate) AS azn')->first();
        if ((float) $docs->amount <= 0) {
            return null;
        }
        $avg = (float) $docs->azn / (float) $docs->amount;
        $rates = app(CurrencyRates::class);
        $realized = 0.0;
        $paidAzn = 0.0;
        foreach (DB::table('finance_documents')->whereNull('cancelled_at')->where('type', 'supplier_payment')->where('supplier_id', $supplier->id)
            ->get(['date', 'currency', 'amount', 'to_amount']) as $p) {
            // ödəniş AZN hesabdandırsa məbləğ AZN-dir, valyuta hesabından — ödəniş gününün kursu ilə
            $azn = $p->currency === 'AZN' ? (float) $p->amount : (float) $p->amount * (float) ($rates->rate($p->currency, substr((string) $p->date, 0, 10)) ?? $avg);
            $paidAzn += $azn;
            $realized += $azn - (float) ($p->to_amount ?? $p->amount) * $avg;
        }
        $balance = $this->balances([$supplier->id])[$supplier->id]['balance'] ?? 0.0;
        $current = $rates->rate($supplier->currency, now()->toDateString());

        return ['avg_rate' => round($avg, 4), 'current_rate' => $current, 'realized' => round($realized, 2), 'paid_azn' => round($paidAzn, 2),
            'unrealized' => $current !== null ? round($balance * ($current - $avg), 2) : null];
    }

    /**
     * Üzləşmə (təchizatçı valyutasında): tarix üzrə mədaxil / qaytarma / ödəniş və qalıq.
     *
     * @return list<array{date: string, type: string, doc: string, url: string|null, credit: float, debit: float, balance: float}>
     */
    public function statement(int $supplierId): array
    {
        $rows = collect();
        foreach (DB::table('purchase_documents')->whereNull('cancelled_at')->where('supplier_id', $supplierId)->get(['id', 'type', 'date', 'doc_no', 'total_amount']) as $d) {
            $rows->push(['date' => substr((string) $d->date, 0, 10), 'type' => $d->type === 'receipt' ? __('Mədaxil') : __('Qaytarma'), 'doc' => $d->doc_no,
                'url' => route('purchase-docs.show', $d->id), 'credit' => $d->type === 'receipt' ? (float) $d->total_amount : 0.0, 'debit' => $d->type === 'return' ? (float) $d->total_amount : 0.0]);
        }
        foreach (DB::table('finance_documents')->whereNull('cancelled_at')->where('type', 'supplier_payment')->where('supplier_id', $supplierId)->get(['id', 'date', 'doc_no', 'amount', 'to_amount']) as $f) {
            $rows->push(['date' => substr((string) $f->date, 0, 10), 'type' => __('Ödəniş'), 'doc' => $f->doc_no, 'url' => route('finance.documents.show', $f->id),
                'credit' => 0.0, 'debit' => (float) ($f->to_amount ?? $f->amount)]);
        }
        $balance = 0.0;

        return $rows->sortBy(['date', 'doc'])->values()->map(function ($r) use (&$balance) {
            $balance += $r['credit'] - $r['debit'];

            return $r + ['balance' => round($balance, 2)];
        })->all();
    }
}
