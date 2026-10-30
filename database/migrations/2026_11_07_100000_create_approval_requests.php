<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Təsdiq axını: limit / endirim / minimum qiymət aşan sifariş rəhbərin təsdiqinə göndərilir;
 * təsdiqlənməyənə qədər təhvil verilmir və fakturalanmır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);                              // sales_order
            $table->unsignedBigInteger('subject_id');
            $table->json('reasons');                                         // aşılan qaydalar (mətn)
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('status', 12)->default('pending')->index();       // pending | approved | rejected | superseded
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('approval_status', 12)->nullable();               // null — lazım deyil | pending | approved | rejected
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('approval_status'));
        Schema::dropIfExists('approval_requests');
    }
};
