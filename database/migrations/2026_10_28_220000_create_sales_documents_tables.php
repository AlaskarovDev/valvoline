<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satış sənədləri axını: Sifariş → Təhvil-təslim sənədi → Satış fakturası.
 *  - sifariş (sales_order_items) yalnız rezerv edir: real stoka və müştəri borcuna təsir etmir;
 *  - təhvil-təslim (delivery_notes) real stoku azaldır (FIFO maya burada hesablanır);
 *  - faktura (sales_invoices) satış sətirlərini (sales_lines) yaradır: gəlir və müştəri borcu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // əl ilə sifariş: open (rezervdə) | shipped (təhvil verilib) | invoiced (fakturalanıb)
            $table->string('status', 12)->nullable()->after('source')->index();
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->string('op_type', 16);                          // sale | gift | internal
            $table->decimal('qty', 14, 4);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('amount', 14, 4)->default(0);
            $table->decimal('discount_pct', 8, 6)->default(0);
            $table->decimal('campaign_discount', 14, 4)->default(0);
            $table->decimal('net_amount', 14, 4)->default(0);
            $table->unsignedBigInteger('campaign_id')->nullable()->index(); // kampaniya hədiyyəsi
            $table->timestamps();
            $table->index('product_id');
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->decimal('total_qty', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->decimal('total_cogs', 14, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_no');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 64);
            $table->string('product_name')->nullable();
            $table->string('op_type', 16);
            $table->decimal('qty', 14, 4);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('net_amount', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('cogs', 14, 4)->nullable();
            $table->timestamps();
            $table->index('product_id');
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            $table->date('date')->index();
            $table->char('period', 7)->index();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_note_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->decimal('gross_amount', 14, 4)->default(0);
            $table->decimal('discount_amount', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('sales_lines', function (Blueprint $table) {
            $table->foreignId('sales_invoice_id')->nullable()->after('sales_order_id')->constrained()->cascadeOnDelete();
            // maya və stok çıxışı təhvil-təslim sətrindən gəlir (fakturanın özü stoku azaltmır)
            $table->foreignId('delivery_note_line_id')->nullable()->after('sales_invoice_id')->constrained()->nullOnDelete();
        });

        // artıq sistemdə yaradılmış sifarişlər: sətirləri rezervə köçürülür (satış sayılmırdı — fakturalanmayıb)
        $now = now();
        foreach (DB::table('sales_orders')->where('source', 'manual')->pluck('id') as $orderId) {
            foreach (DB::table('sales_lines')->where('sales_order_id', $orderId)->orderBy('row_no')->get() as $line) {
                DB::table('sales_order_items')->insert([
                    'sales_order_id' => $orderId, 'row_no' => $line->row_no, 'product_id' => $line->product_id,
                    'product_code' => $line->product_code, 'product_name' => $line->product_name, 'op_type' => $line->op_type,
                    'qty' => $line->qty, 'price' => $line->price, 'amount' => $line->amount, 'discount_pct' => $line->discount_pct,
                    'campaign_discount' => 0, 'net_amount' => $line->net_amount, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('sales_lines')->where('sales_order_id', $orderId)->delete();
            DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'open', 'total_cogs' => 0, 'gross_profit' => 0, 'net_profit' => 0]);
        }
    }

    public function down(): void
    {
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_note_line_id');
            $table->dropConstrainedForeignId('sales_invoice_id');
        });
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('sales_order_items');
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
