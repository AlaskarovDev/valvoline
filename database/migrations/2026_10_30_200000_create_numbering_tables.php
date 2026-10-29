<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nömrələmə servisi: hər sənəd / kod növü üçün şablon (məs. S{IYN}.{YY}{MM}{DD}.{SEQ:4}), rejim və sayğaclar.
 * Qaydalar ilk istifadədə NumberingService::RULES-dan avtomatik yaradılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('pattern', 64);
            $table->string('mode', 16)->default('auto');      // auto | auto_editable | manual
            $table->string('reset', 10)->default('prefix');   // prefix | never | yearly | monthly | daily
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('numbering_counters', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 40);
            $table->string('scope', 96)->default('');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
            $table->unique(['rule_key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_counters');
        Schema::dropIfExists('numbering_rules');
    }
};
