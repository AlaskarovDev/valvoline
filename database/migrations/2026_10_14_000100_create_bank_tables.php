<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank hesabları və onların əməliyyatları (bank çıxarışından import və ya əl ilə daxil edilmiş).
 * Ay sonu qalıqları mühasibat faylından gəlir (bank_flow_balances.account = bank_accounts.name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();           // mühasibat faylındakı ad: «Turan Bank AZN»
            $table->string('bank')->nullable();
            $table->string('currency', 3)->default('AZN');
            $table->string('kind', 16)->default('bank'); // bank | card | cash | deposit
            $table->string('iban', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->string('counterparty')->nullable();
            $table->string('normalized')->nullable()->index();
            $table->text('description')->nullable();
            $table->decimal('amount_in', 16, 4)->default(0);
            $table->decimal('amount_out', 16, 4)->default(0);
            $table->decimal('balance', 16, 4)->nullable();  // çıxarışdakı qalıq (varsa)
            $table->string('doc_no', 64)->nullable();
            $table->string('source', 16)->default('manual'); // statement | manual
            $table->string('file_name')->nullable();
            $table->string('row_hash', 40)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['bank_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};
