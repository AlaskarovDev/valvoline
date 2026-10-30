<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * e-qaimə (e-taxes.gov.az):
 *  - satış fakturası InvoiceBot / e-taxes «Təqdim etmə» Excel nümunəsinə ixrac olunur, seriya nömrəsi və statusu
 *    e-taxes-in öz Excel çıxışından (EQF) geri tutuşdurulur;
 *  - daxil olan (alış) e-qaimələri EQF Excel-indən reyestrə yüklənir, mallar məhsullara uyğunlaşdırılır,
 *    reyestrdən mədaxil qaiməsi yaradılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('etax_serial', 32)->nullable()->index();
            $table->string('etax_status', 64)->nullable();
            $table->timestamp('etax_exported_at')->nullable();
        });
        Schema::table('sales_return_documents', function (Blueprint $table) {
            $table->timestamp('etax_exported_at')->nullable();
        });

        Schema::create('incoming_einvoices', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 32)->unique();
            $table->string('kind')->nullable();                             // «Tipi»: təqdim etmə, avans, qaytarma …
            $table->string('status', 64)->nullable();                       // «Vəziyyəti»: Təsdiqləndi, Ləğv edildi …
            $table->string('sender_tin', 16)->index();
            $table->string('sender_name')->nullable();
            $table->string('receiver_tin', 16)->nullable();
            $table->date('issued_at')->nullable()->index();
            $table->text('note')->nullable();
            $table->text('extra_note')->nullable();
            $table->decimal('vatable_amount', 14, 4)->default(0);
            $table->decimal('not_subject_amount', 14, 4)->default(0);
            $table->decimal('exempt_amount', 14, 4)->default(0);
            $table->decimal('zero_rate_amount', 14, 4)->default(0);
            $table->decimal('base_amount', 14, 4)->default(0);              // ƏDV-siz
            $table->decimal('vat_amount', 14, 4)->default(0);
            $table->decimal('road_tax', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->string('source_serial', 64)->nullable();                // qaytarma: əsas qaimənin seriyası
            $table->json('items');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // təchizatçının mal adı → bizim məhsul (növbəti qaimələrdə avtomatik); factor: onların 1 vahidi = bizim N vahid
        Schema::create('einvoice_product_maps', function (Blueprint $table) {
            $table->id();
            $table->string('sender_tin', 16);
            $table->string('item_name');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('factor', 12, 4)->default(1);
            $table->timestamps();
            $table->unique(['sender_tin', 'item_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoice_product_maps');
        Schema::dropIfExists('incoming_einvoices');
        Schema::table('sales_return_documents', fn (Blueprint $table) => $table->dropColumn('etax_exported_at'));
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['etax_serial']);
            $table->dropColumn(['etax_serial', 'etax_status', 'etax_exported_at']);
        });
    }
};
