<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `analytics:rebuild` tərəfindən sıfırdan doldurulan cədvəllər.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->unsignedInteger('seq');                         // qlobal sıra (tarix + gün daxili prioritet)
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->index();                    // purchase|opening|sale|gift|internal_use|return|transfer_out|transfer_in|adjustment
            $table->decimal('qty', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->decimal('running_qty', 14, 4)->default(0);      // mal üzrə (bütün anbarlar)
            $table->decimal('running_wh_qty', 14, 4)->default(0);   // mal + anbar üzrə
            $table->decimal('running_avg_cost', 14, 4)->default(0);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('doc_no', 64)->nullable();
            $table->index(['product_id', 'seq']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('monthly_sales_summary', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->index();
            $table->unsignedSmallInteger('year')->index();
            $table->string('kind', 24)->index();                     // sale | gift | internal | return_customer | return_internal
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('qty', 14, 4)->default(0);
            $table->decimal('liters', 14, 4)->default(0);
            $table->decimal('gross_amount', 14, 4)->default(0);      // Mebleg (endirimsiz)
            $table->decimal('net_amount', 14, 4)->default(0);        // Yekun
            $table->decimal('cogs', 14, 4)->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->index('product_id');
            $table->index('customer_id');
            $table->index('manager_id');
        });

        Schema::create('customer_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('sales_total', 14, 4)->default(0);
            $table->decimal('returns_total', 14, 4)->default(0);
            $table->decimal('payments_total', 14, 4)->default(0);
            $table->decimal('balance', 14, 4)->default(0);
            $table->decimal('aging_0_30', 14, 4)->default(0);
            $table->decimal('aging_31_60', 14, 4)->default(0);
            $table->decimal('aging_61_90', 14, 4)->default(0);
            $table->decimal('aging_90_plus', 14, 4)->default(0);
            $table->date('oldest_unpaid_date')->nullable();
            $table->date('first_sale_date')->nullable();
            $table->date('last_sale_date')->nullable();
            $table->date('last_payment_date')->nullable();
            $table->unsignedInteger('order_count')->default(0);
            $table->date('as_of')->nullable();
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 14, 4)->default(0);
            $table->date('last_movement_date')->nullable();
            $table->unique(['product_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('customer_balances');
        Schema::dropIfExists('monthly_sales_summary');
        Schema::dropIfExists('stock_movements');
    }
};
