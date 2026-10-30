<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müştəri kartı və kredit nəzarəti:
 *  - əlaqə məlumatları, region, ödəniş müddəti (gün), kredit limiti, status (aktiv / bloklu);
 *  - fakturanın ödəniş tarixi (faktura tarixi + müştərinin ödəniş müddəti);
 *  - balans cədvəlində gecikmiş borc (ödəniş tarixi keçmiş, FIFO ilə bağlanmamış hissə) və ən uzun gecikmə.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('region', 64)->nullable()->index();
            $table->unsignedSmallInteger('payment_days')->nullable();      // boş = dərhal (0 gün)
            $table->decimal('credit_limit', 14, 2)->nullable();            // boş = limitsiz
            $table->string('status', 12)->default('active')->index();      // active | blocked
            $table->string('block_reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('date');
        });
        Schema::table('customer_balances', function (Blueprint $table) {
            $table->decimal('overdue_amount', 14, 4)->default(0);
            $table->unsignedInteger('overdue_days')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('customer_balances', fn (Blueprint $t) => $t->dropColumn(['overdue_amount', 'overdue_days']));
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn('due_date'));
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['region']);
            $table->dropIndex(['status']);
            $table->dropColumn(['phone', 'email', 'address', 'contact_person', 'region', 'payment_days', 'credit_limit', 'status', 'block_reason', 'note']);
        });
    }
};
