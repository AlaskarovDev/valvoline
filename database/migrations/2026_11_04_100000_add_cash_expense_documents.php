<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kassa məxarici (KX-), təhtəlhesab avans (AV-) və avans hesabatı (AH-).
 * Xərclər cash_transactions-a «Xərc» kimi yazılır (finance_document_id ilə) — Xərclər və mənfəət-zərər hesabatına düşür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_documents', function (Blueprint $table) {
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();   // təhtəlhesab şəxs
            $table->string('direction', 64)->nullable();
        });
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreignId('finance_document_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('finance_document_id'));
        Schema::table('finance_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_category_id');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('direction');
        });
    }
};
