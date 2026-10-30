<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alış sifarişi (AS-): təchizatçıya sifariş; mədaxil qaiməsi ona bağlanır, qəbul olunan miqdar aktiv mədaxillərdən hesablanır.
 * suppliers.lead_days — sifarişdən anbara çatana qədər gün (satınalma təklifi üçün).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->date('date')->index();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3)->default('AZN');
            $table->date('expected_date')->nullable();
            $table->decimal('total_amount', 16, 4)->default(0);            // valyutada, ƏDV-siz
            $table->text('note')->nullable();
            $table->timestamp('closed_at')->nullable();                     // qalığı gözlənilmir (əl ilə bağlanıb)
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);
            $table->decimal('price', 16, 6)->default(0);                    // valyutada, ƏDV-siz
            $table->decimal('amount', 16, 4)->default(0);
            $table->timestamps();
        });
        Schema::table('purchase_documents', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('lead_days'));
        Schema::table('purchase_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_order_id'));
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
