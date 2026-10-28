<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistemdə əl ilə daxil edilən sifarişlər: anbar seçimi, qeyd və mənbə (import | manual).
 * Əl ilə daxil edilən sifarişin import_batch_id-si boşdur — təkrar import onu silmir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('manager_id')->constrained()->nullOnDelete();
            $table->text('note')->nullable()->after('net_profit');
            $table->string('source', 16)->default('import')->after('note')->index();
            $table->foreignId('created_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
        });
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('manager_name')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['source']);
            $table->dropColumn(['note', 'source']);
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
