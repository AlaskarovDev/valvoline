<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sənəd bütövlüyü:
 *  - audit_logs: hər dəyişiklik sorğusu (kim, nə vaxt, nə etdi, hansı sənəd, IP) — RecordAudit middleware yazır;
 *  - app_settings: ümumi tənzimləmələr (məs. dövr bağlama tarixi);
 *  - sənədlər silinmir, «ləğv edilib» olur: başlıq və sətirlər qalır, yalnız təsiri (stok, borc, ödəniş) götürülür.
 *    Satış fakturasının sətirləri (sales_lines) təsirin özüdür — ləğvdə surəti cancelled_lines-a yazılır.
 */
return new class extends Migration
{
    private const CANCELLABLE = ['sales_orders', 'delivery_notes', 'sales_invoices', 'stock_documents', 'stock_write_offs', 'finance_documents'];

    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action', 64)->index();              // marşrut adı: invoices.destroy, auth.login …
            $table->string('method', 8);
            $table->string('subject_type', 40)->nullable();     // SalesInvoice, Product …
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('doc_nos')->nullable();               // |SF-000012|TT-000034| — sənəd tarixçəsi üçün axtarış
            $table->text('summary')->nullable();
            $table->longText('payload')->nullable();             // json: input, before, changes
            $table->string('outcome', 12)->default('ok')->index(); // ok | error | denied
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (self::CANCELLABLE as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->timestamp('cancelled_at')->nullable()->index();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('cancel_reason', 500)->nullable();
                if ($name === 'sales_invoices') {
                    $table->longText('cancelled_lines')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::CANCELLABLE as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropConstrainedForeignId('cancelled_by');
                $table->dropIndex(['cancelled_at']);
                $table->dropColumn(array_merge(['cancelled_at', 'cancel_reason'], $name === 'sales_invoices' ? ['cancelled_lines'] : []));
            });
        }
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('audit_logs');
    }
};
