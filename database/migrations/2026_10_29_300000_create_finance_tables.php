<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maliyyə: iş yerləri (bütün sənədlərdə), satış nümayəndəsi kartı, kassalar (kodla, nümayəndəyə bağlı),
 * banklar (bank → valyuta hesabları) və maliyyə sənədləri:
 * kassa açılışı, kassa mədaxili (müştəri ödənişi), kassa transferi, bank açılışı, banklar arası transfer.
 */
return new class extends Migration
{
    private const DOC_TABLES = ['sales_orders', 'delivery_notes', 'sales_invoices', 'warehouse_transfers', 'stock_write_offs'];

    public function up(): void
    {
        Schema::create('workplaces', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name')->unique();
            $table->string('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $now = now();
        $default = DB::table('workplaces')->insertGetId(['code' => 'IY-01', 'name' => 'Mərkəzi ofis', 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('workplace_id')->nullable()->after('manager_id')->constrained()->nullOnDelete();
        });
        foreach (self::DOC_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            });
            // mövcud sistem sənədləri əsas iş yerinə yazılır
            DB::table($name)->when($name === 'sales_orders', fn ($q) => $q->where('source', 'manual'))->update(['workplace_id' => $default]);
        }

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name')->unique();
            $table->char('currency', 3)->default('AZN');
            $table->string('type', 10)->default('rep');            // main | rep
            $table->foreignId('manager_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('managers', function (Blueprint $table) {
            $table->string('code', 16)->nullable()->unique()->after('name');
            $table->string('phone', 32)->nullable();
            $table->string('role', 16)->default('rep');            // rep (satış nümayəndəsi) | forwarder (ekspeditor)
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('monthly_target', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
        });

        // əsas kassa + hər satış nümayəndəsinə ayrıca kassa
        DB::table('cash_registers')->insert(['code' => 'KS-001', 'name' => 'Əsas kassa', 'type' => 'main', 'workplace_id' => $default, 'created_at' => $now, 'updated_at' => $now]);
        $n = 1;
        foreach (DB::table('managers')->orderBy('id')->get(['id', 'name']) as $m) {
            $n++;
            DB::table('managers')->where('id', $m->id)->update(['code' => 'SN-'.str_pad((string) ($n - 1), 3, '0', STR_PAD_LEFT), 'workplace_id' => $default]);
            DB::table('cash_registers')->insert(['code' => 'KS-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'name' => 'Kassa — '.$m->name, 'type' => 'rep',
                'manager_id' => $m->id, 'workplace_id' => $default, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 32)->nullable();                // bank kodu
            $table->string('swift', 16)->nullable();
            $table->string('voen', 16)->nullable();
            $table->string('correspondent_account', 64)->nullable();
            $table->timestamps();
        });
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('bank_id')->nullable()->after('bank')->constrained()->nullOnDelete();
        });
        foreach (DB::table('bank_accounts')->whereNotNull('bank')->where('bank', '<>', '')->distinct()->pluck('bank') as $bank) {
            $id = DB::table('banks')->insertGetId(['name' => $bank, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('bank_accounts')->where('bank', $bank)->update(['bank_id' => $id]);
        }

        Schema::create('finance_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 32)->unique();
            // cash_opening | cash_receipt | cash_transfer | bank_opening | bank_transfer
            $table->string('type', 16)->index();
            $table->date('date')->index();
            $table->foreignId('workplace_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('to_cash_register_id')->nullable()->constrained('cash_registers')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('to_bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->decimal('amount', 16, 4);                       // mənbə / əsas məbləğ
            $table->decimal('to_amount', 16, 4)->nullable();        // valyutalar fərqlidirsə qəbul edən hesaba
            $table->char('currency', 3)->default('AZN');
            $table->decimal('rate', 16, 6)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_document_id')->nullable()->constrained()->cascadeOnDelete();
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('finance_document_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('finance_document_id'));
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('finance_document_id');
            $t->dropConstrainedForeignId('cash_register_id');
        });
        Schema::dropIfExists('finance_documents');
        Schema::table('bank_accounts', fn (Blueprint $t) => $t->dropConstrainedForeignId('bank_id'));
        Schema::dropIfExists('banks');
        Schema::table('managers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('warehouse_id');
            $t->dropConstrainedForeignId('workplace_id');
            $t->dropUnique(['code']);
            $t->dropColumn(['code', 'phone', 'role', 'monthly_target', 'is_active']);
        });
        Schema::dropIfExists('cash_registers');
        foreach (self::DOC_TABLES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('workplace_id'));
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('workplace_id'));
        Schema::dropIfExists('workplaces');
    }
};
