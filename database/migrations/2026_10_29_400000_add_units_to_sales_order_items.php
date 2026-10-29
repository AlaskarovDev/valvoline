<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sifariş sətrində seçilmiş vahid (məs. 2 bidon = 50 ədəd). Stok, maya və faktura əsas vahiddə qalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_name')->constrained()->nullOnDelete();
            $table->string('unit_name', 32)->nullable()->after('product_unit_id');
            $table->decimal('unit_factor', 14, 4)->default(1)->after('unit_name');
            $table->decimal('unit_qty', 14, 4)->nullable()->after('unit_factor');
            $table->decimal('unit_price', 14, 4)->nullable()->after('unit_qty');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_name', 'unit_factor', 'unit_qty', 'unit_price']);
        });
    }
};
