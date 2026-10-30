<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alış modulu:
 *  - suppliers: təchizatçı kartı (hesablaşma valyutası, ödəniş müddəti);
 *  - purchase_documents (+ lines): mədaxil qaiməsi (receipt) və təchizatçıya qaytarma (return).
 *    Mədaxil purchase_lines-a yazılır (FIFO maya mövcud mexanizmlə), qaytarma stock_adjustments-a (mənfi).
 *    Valyuta + Mərkəzi Bank məzənnəsi; gömrük / daşınma / digər xərclər (AZN) sətirlərə dəyər nisbətində paylanır;
 *  - currency_rates: Mərkəzi Bankın gündəlik məzənnələri (keş);
 *  - finance_documents.supplier_id: təchizatçıya ödəniş sənədi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('voen', 32)->nullable();
            $table->string('country', 64)->nullable();
            $table->char('currency', 3)->default('AZN');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->string('type', 12)->index();                          // receipt | return
            $table->date('date')->index();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('original_document_id')->nullable()->constrained('purchase_documents')->nullOnDelete();
            $table->char('currency', 3)->default('AZN');
            $table->decimal('rate', 16, 6)->default(1);                    // 1 valyuta = rate AZN
            $table->string('supplier_invoice_no', 64)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('vat_included')->default(false);
            $table->decimal('goods_amount', 16, 4)->default(0);            // valyutada, ƏDV-siz
            $table->decimal('vat_amount', 16, 4)->default(0);              // valyutada
            $table->decimal('total_amount', 16, 4)->default(0);            // valyutada — təchizatçı borcu
            $table->decimal('customs_cost', 14, 4)->default(0);            // AZN
            $table->decimal('transport_cost', 14, 4)->default(0);          // AZN
            $table->decimal('other_cost', 14, 4)->default(0);              // AZN
            $table->decimal('total_cost_azn', 16, 4)->default(0);          // stoka düşən dəyər (AZN)
            $table->text('note')->nullable();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);
            $table->decimal('price', 16, 6);                               // valyutada, sənəddəki kimi
            $table->decimal('amount', 16, 4);                              // valyutada, ƏDV-siz
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 16, 4)->default(0);
            $table->decimal('extra_cost_azn', 14, 4)->default(0);          // paylanmış əlavə xərc
            $table->decimal('unit_cost_azn', 14, 4)->default(0);           // stok mayası
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->char('code', 3);
            $table->decimal('rate', 16, 8);
            $table->timestamps();
            $table->unique(['date', 'code']);
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('doc_no', 32)->nullable();
        });
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->foreignId('purchase_document_id')->nullable()->constrained()->cascadeOnDelete();
        });
        Schema::table('finance_documents', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_documents', fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        Schema::table('stock_adjustments', fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_document_id'));
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_document_id');
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn('doc_no');
        });
        Schema::dropIfExists('currency_rates');
        Schema::dropIfExists('purchase_document_lines');
        Schema::dropIfExists('purchase_documents');
        Schema::dropIfExists('suppliers');
    }
};
