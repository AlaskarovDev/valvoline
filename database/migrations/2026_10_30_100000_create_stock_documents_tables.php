<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Məhsul idarəetməsi → Sənədlər: açılış, sayım artığı, sayım əskiyi, istehsal itkisi, sərf.
 *  - açılış: stoka ilkin qalıq + maya (CostingService-də «opening» hadisəsi);
 *  - digərləri stock_adjustments-a yazılır (artıq → +, əskik/itki/sərf → −).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->string('type', 20)->index();             // opening | count_surplus | count_shortage | production_loss | consumption
            $table->date('date')->index();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose')->nullable();           // sərf məqsədi: nümunə, ofis, reklam …
            $table->text('note')->nullable();
            $table->decimal('total_qty', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('system_qty', 14, 4)->nullable();  // sayım: sistemdəki qalıq (sənəd anında)
            $table->decimal('counted_qty', 14, 4)->nullable(); // sayım: fiziki sayılan
            $table->decimal('qty', 14, 4);                     // əsas vahiddə, həmişə müsbət
            $table->decimal('unit_cost', 14, 4)->nullable();   // açılış: daxil edilən maya; digərləri: hesablanan
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->foreignId('stock_document_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', fn (Blueprint $t) => $t->dropConstrainedForeignId('stock_document_id'));
        Schema::dropIfExists('stock_document_lines');
        Schema::dropIfExists('stock_documents');
    }
};
