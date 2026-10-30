<?php

use App\Support\Vat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satış sənədlərində ƏDV: faktura sətrində ƏDV rejimi, dərəcəsi və məbləği (fakturalama anındakı kimi saxlanılır),
 * fakturada ƏDV-siz məbləğ və ƏDV cəmi. Mövcud fakturalar malın cari ƏDV parametrləri ilə doldurulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->string('vat_mode', 12)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 14, 4)->nullable();
        });
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->decimal('amount_ex_vat', 14, 4)->default(0)->after('total_amount');
            $table->decimal('vat_amount', 14, 4)->default(0)->after('amount_ex_vat');
        });

        $products = DB::table('products')->get(['id', 'vat_mode', 'vat_rate'])->keyBy('id');
        $totals = [];
        foreach (DB::table('sales_lines')->whereNotNull('sales_invoice_id')->get(['id', 'sales_invoice_id', 'product_id', 'net_amount', 'op_type']) as $line) {
            $p = $products[$line->product_id] ?? null;
            $split = Vat::split($line->op_type === 'sale' ? (float) $line->net_amount : 0.0, $p->vat_mode ?? 'standard', $p->vat_rate ?? 18);
            DB::table('sales_lines')->where('id', $line->id)->update(['vat_mode' => $p->vat_mode ?? 'standard', 'vat_rate' => $split['rate'], 'vat_amount' => $split['vat']]);
            $totals[$line->sales_invoice_id] = ($totals[$line->sales_invoice_id] ?? 0.0) + $split['vat'];
        }
        foreach (DB::table('sales_invoices')->get(['id', 'total_amount']) as $invoice) {
            $vat = round($totals[$invoice->id] ?? 0.0, 4);
            DB::table('sales_invoices')->where('id', $invoice->id)->update(['vat_amount' => $vat, 'amount_ex_vat' => round((float) $invoice->total_amount - $vat, 4)]);
        }
    }

    public function down(): void
    {
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn(['amount_ex_vat', 'vat_amount']));
        Schema::table('sales_lines', fn (Blueprint $t) => $t->dropColumn(['vat_mode', 'vat_rate', 'vat_amount']));
    }
};
