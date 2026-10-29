<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anbar modulu:
 *  - anbar kartı (növ: əsas / səyyar maşın / regional / tranzit / karantin, məsul, maşın nömrəsi);
 *  - anbarlar arası transfer sənədi (göndərildi → yolda (tranzit anbar) → qəbul edildi, qismən qəbul, kəsir);
 *  - partiyalar: istehsal və son istifadə tarixi (FEFO), silinmə aktı (vaxtı bitmiş mal).
 * Stok hərəkəti mövcud stock_transfers / stock_adjustments cədvəllərinə yazılır — maya (FIFO) avtomatik daşınır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('type', 12)->default('main')->after('code');    // main | van | regional | transit | quarantine
            $table->string('address')->nullable()->after('type');
            $table->string('responsible')->nullable()->after('address');
            $table->string('vehicle_plate', 20)->nullable()->after('responsible');
            $table->foreignId('manager_id')->nullable()->after('vehicle_plate')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('is_main');
        });
        DB::table('warehouses')->where('is_main', false)->update(['type' => 'van']);
        $now = now();
        DB::table('warehouses')->insert(['name' => 'Yolda (tranzit)', 'code' => 'TRANSIT', 'type' => 'transit', 'is_main' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->date('date')->index();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 12)->default('draft')->index();       // draft | sent | partial | received | cancelled
            $table->string('reason', 20)->default('replenish');            // replenish | van_load | van_return | rebalance | quarantine | other
            $table->string('driver')->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->text('note')->nullable();
            $table->date('sent_date')->nullable();
            $table->date('received_date')->nullable();
            $table->decimal('total_qty', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);                                 // göndərilən
            $table->decimal('received_qty', 14, 4)->nullable();            // qəbul edilən
            $table->decimal('written_off_qty', 14, 4)->default(0);         // kəsir kimi silinən
            $table->string('discrepancy_note')->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamps();
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('warehouse_transfer_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::create('stock_write_offs', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->date('date')->index();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('reason', 20)->default('expired');              // expired | damaged | shortage | transfer_shortage | other
            $table->text('note')->nullable();
            $table->decimal('total_qty', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->foreignId('warehouse_transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no', 64);
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->boolean('expiry_estimated')->default(false);           // istehsal tarixi + saxlanma müddətindən hesablanıb
            $table->decimal('qty', 14, 4);                                 // daxil olan miqdar (əsas vahid)
            $table->decimal('written_off_qty', 14, 4)->default(0);
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('received_date')->nullable();
            $table->string('supplier')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'batch_no']);
        });

        Schema::create('stock_write_off_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_write_off_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->decimal('qty', 14, 4);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamps();
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->foreignId('stock_write_off_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('expiry_alert_days')->nullable();  // bitməyə N gün qalmış xəbərdarlıq
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('expiry_alert_days'));
        Schema::table('stock_adjustments', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_write_off_id'));
        Schema::dropIfExists('stock_write_off_lines');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('stock_write_offs');
        Schema::table('stock_transfers', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_transfer_id'));
        Schema::dropIfExists('warehouse_transfer_lines');
        Schema::dropIfExists('warehouse_transfers');
        DB::table('warehouses')->where('code', 'TRANSIT')->delete();
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['type', 'address', 'responsible', 'vehicle_plate', 'is_active']);
        });
    }
};
