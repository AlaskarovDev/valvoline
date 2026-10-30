<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müştəri qaytarma qaiməsi (kredit-qeyd): fakturaya istinadla və ya istinadsız.
 * Təsiri sales_returns cədvəlinə yazılır (import qaytarmaları ilə eyni mexanizm): müştəri borcu azalır,
 * mal seçilmiş anbara (məs. karantin anbarına) qayıdır, maya FIFO ilə hesablanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->date('date')->index();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 32);                                   // quality | wrong_item | expired | damaged | other
            $table->text('note')->nullable();
            $table->decimal('total_amount', 14, 4)->default(0);              // ƏDV daxil
            $table->decimal('vat_amount', 14, 4)->default(0);
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('sales_return_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->foreignId('sales_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);
            $table->decimal('price', 14, 4);                                 // ƏDV daxil vahid qiymət (endirimli)
            $table->decimal('net_amount', 14, 4);
            $table->string('vat_mode', 12)->nullable();
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 14, 4)->default(0);
            $table->timestamps();
        });
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreignId('sales_return_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('sales_return_document_id');
        });
        Schema::dropIfExists('sales_return_document_lines');
        Schema::dropIfExists('sales_return_documents');
    }
};
