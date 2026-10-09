<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kataloqlar: anbarlar, menecerlər, kateqoriyalar, mallar, müştərilər, import partiyaları.
 * Bütün pul sahələri DECIMAL(14,4) — yuvarlaqlaşdırma yalnız göstərmədə.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_type', 32)->index();      // products, customers, purchases, sales, returns, payments, stock, cash
            $table->string('file_name');
            $table->string('stored_path')->nullable();
            $table->string('status', 16)->default('preview'); // preview | done | rolled_back | failed
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_pending')->default(0);
            $table->unsignedInteger('rows_error')->default(0);
            $table->json('sheets')->nullable();             // vərəq => sətir sayı
            $table->json('errors')->nullable();             // ilk N xəta
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('admin')->after('password'); // admin | menecer
            $table->unsignedBigInteger('manager_id')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('manager_id');
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 16)->unique();
            $table->boolean('is_main')->default(false);
            $table->timestamps();
        });

        Schema::create('managers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('series')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();          // həmişə mətn: VE11258, 885852
            $table->string('name');
            $table->foreignId('product_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('group_code_count')->nullable();
            $table->string('series')->nullable()->index();
            $table->string('viscosity')->nullable();
            $table->decimal('volume_l', 10, 3)->default(0);
            $table->unsignedInteger('pack_qty')->nullable();
            $table->string('pack_type')->nullable();
            $table->decimal('sale_price', 14, 4)->default(0);
            $table->decimal('file_last_cost', 14, 4)->nullable();   // Son_Maya_Deyeri (fayldan, müqayisə üçün)
            $table->decimal('file_avg_cost', 14, 4)->nullable();    // Orta_Maya_Deyeri (fayldan, müqayisə üçün)
            $table->text('old_codes')->nullable();
            // hesablanan
            $table->decimal('current_avg_cost', 14, 4)->nullable();
            $table->decimal('stock_qty', 14, 4)->default(0);
            $table->boolean('cost_estimated')->default(false);
            $table->decimal('min_stock_qty', 14, 4)->nullable();    // "az qalıb" həddi (boşdursa 14 günlük satış)
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->string('voen', 32)->nullable();
            $table->string('type', 16)->default('customer');       // customer | internal
            $table->date('first_sale')->nullable();
            $table->date('last_sale')->nullable();
            $table->text('other_names')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index('manager_id');
            $table->index('name');
        });

        Schema::create('customer_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('normalized')->index();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('customer_code', 64)->nullable();
            $table->string('main_name')->nullable();
            $table->string('method')->nullable();                  // fayldakı Uygunlasdirma_Usulu və ya "Əl ilə"
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('op_type', 32)->default('Xərc');        // Xərc | Mədaxil | Köçürmə ...
            $table->boolean('affects_profit')->default(true);
            $table->boolean('user_edited')->default(false);       // UI-dan dəyişilibsə təkrar import bayrağı ezmir
            $table->unsignedInteger('row_count')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('customer_aliases');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_groups');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('managers');
        Schema::dropIfExists('warehouses');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'manager_id', 'is_active']));
        Schema::dropIfExists('import_batches');
    }
};
