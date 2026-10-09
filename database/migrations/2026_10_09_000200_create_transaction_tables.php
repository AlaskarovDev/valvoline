<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Əməliyyat cədvəlləri. Hər sətirdə `period` (YYYY-MM) saxlanılır ki, aylıq qruplaşdırma
 * həm MySQL, həm SQLite-da eyni SQL ilə işləsin. `status`: ok | pending (tanınmayan kod).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('batch_no')->index();
            $table->string('name')->nullable();
            $table->date('date')->nullable();
            $table->string('date_source')->nullable();
            $table->unsignedInteger('sku_count')->default(0);
            $table->decimal('total_qty', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('batch_no')->index();
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->decimal('orig_qty', 14, 4)->nullable();
            $table->string('orig_unit')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_estimated')->default(false);
            $table->string('status', 16)->default('ok')->index();
            $table->unsignedInteger('row_no');
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('product_id');
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 64)->index();
            $table->string('invoice_no', 64)->nullable();
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('line_count')->default(0);
            $table->decimal('total_amount', 14, 4)->default(0);   // Σ Yekun_Mebleg (Satış)
            $table->decimal('total_cogs', 14, 4)->default(0);     // Σ cogs (hər üç tip)
            $table->decimal('gross_profit', 14, 4)->default(0);
            $table->decimal('return_amount', 14, 4)->default(0);
            $table->decimal('return_cogs', 14, 4)->default(0);
            $table->decimal('net_profit', 14, 4)->default(0);     // qaytarmadan sonra xalis qazanc
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('customer_id');
            $table->index('manager_id');
        });

        Schema::create('sales_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->string('order_no', 64)->index();
            $table->string('invoice_no', 64)->nullable();
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manager_name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('original_customer_name')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('amount', 14, 4)->default(0);          // Mebleg (endirimsiz)
            $table->decimal('discount_pct', 8, 6)->default(0);     // 0..1
            $table->decimal('discount_price', 14, 4)->default(0);
            $table->decimal('net_amount', 14, 4)->default(0);      // Yekun_Mebleg
            $table->string('op_type', 16)->index();                // sale | gift | internal
            $table->text('note')->nullable();
            // hesablanan
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('cogs', 14, 4)->nullable();
            $table->decimal('gross_profit', 14, 4)->nullable();
            $table->decimal('margin_pct', 10, 6)->nullable();
            $table->boolean('cost_fallback')->default(false);
            $table->string('row_hash', 40)->nullable()->index();   // təbii açar: Sifaris_No+Mal_Kodu+Miqdar+Yekun+sıra
            $table->string('status', 16)->default('ok')->index();
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('product_id');
            $table->index('customer_id');
            $table->index('manager_id');
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('row_no');
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->string('return_no', 64)->nullable()->index();
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manager_name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('original_customer_name')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('discount_pct', 8, 6)->default(0);
            $table->decimal('net_amount', 14, 4)->default(0);
            $table->string('op_type', 16)->index();                // customer | internal
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete(); // ehtimal olunan sifariş
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('cogs', 14, 4)->nullable();
            $table->string('status', 16)->default('ok')->index();
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('product_id');
            $table->index('customer_id');
            $table->index('manager_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('row_no');
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('original_customer_name')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manager_name')->nullable();
            $table->decimal('cash', 14, 4)->default(0);
            $table->decimal('bank', 14, 4)->default(0);
            $table->decimal('total', 14, 4)->default(0);
            $table->string('received_by')->nullable();
            $table->text('note')->nullable();
            $table->string('row_hash', 40)->nullable()->index();   // təbii açar: Tarix+Musteri_Kodu+Cem+Qeyd+sıra
            $table->string('status', 16)->default('ok')->index();
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('customer_id');
            $table->index('manager_id');
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('row_no');
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('computed_qty', 14, 4)->nullable();
            $table->decimal('counted_qty', 14, 4)->nullable();
            $table->decimal('qty', 14, 4);                         // Duzelis_Miqdari (+/−)
            $table->string('reason')->nullable();
            $table->string('status', 16)->default('ok')->index();
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('product_id');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('row_no');
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->string('doc_no', 64)->nullable();
            $table->string('direction', 8);                        // out | in
            $table->string('from_name')->nullable();
            $table->string('to_name')->nullable();
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);
            $table->text('note')->nullable();
            $table->string('status', 16)->default('ok')->index();
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('product_id');
        });

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('row_no');
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category_name')->nullable();
            $table->string('subcategory')->nullable();
            $table->text('description')->nullable();
            $table->decimal('income', 14, 4)->default(0);
            $table->decimal('expense', 14, 4)->default(0);
            $table->string('op_type', 32)->nullable();             // fayldakı dəyər (orijinal)
            $table->boolean('affects_profit_raw')->default(false); // fayldakı dəyər (orijinal)
            $table->boolean('affects_profit')->default(false)->index(); // effektiv: kateqoriya UI-da dəyişilibsə onun bayrağı
            $table->string('direction', 64)->nullable()->index();  // VALVO | TIR | GLAUST | ...
            $table->string('category_source')->nullable();
            $table->text('note')->nullable();
            $table->text('original_row')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('expense_category_id');
        });

        Schema::create('cash_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('description')->nullable();
            $table->decimal('balance', 14, 4);
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['cash_checkpoints', 'cash_transactions', 'stock_transfers', 'stock_adjustments', 'payments',
            'sales_returns', 'sales_lines', 'sales_orders', 'purchase_lines', 'purchase_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
