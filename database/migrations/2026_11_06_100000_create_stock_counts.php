<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sayım (SY-): anbarda barkodla sayılan miqdarlar qaralamada toplanır (bir neçə nəfər eyni anda sayıya bilər),
 * tamamlananda sistem qalığı ilə müqayisə edilib sayım artığı / əskiyi sənədləri avtomatik yaradılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->date('date')->index();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 12)->default('partial');               // full — sayılmayan mal 0 sayılır | partial — yalnız sayılanlar
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('surplus_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
            $table->foreignId('shortage_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('counted_qty', 14, 4)->default(0);
            $table->decimal('system_qty', 14, 4)->nullable();               // tamamlananda yazılır
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['stock_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
