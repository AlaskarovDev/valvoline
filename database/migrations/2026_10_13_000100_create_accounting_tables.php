<?php

use App\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mühasibat bölməsi: hesablar planı, köhnə hesab uyğunluğu və «Balans / MZ / Cash Flow» faylının məlumatı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name');
            $table->char('section', 1)->index();
            $table->char('group_code', 2)->index();
            $table->char('nature', 1);               // A aktiv · P passiv · K kontr · G gəlir · X xərc · N nəticə · B balansdankənar
            $table->boolean('is_active')->default(true);
            $table->boolean('user_edited')->default(false);
            $table->timestamps();
        });

        Schema::create('legacy_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();      // köhnə nömrə (41, 62 …)
            $table->string('chart_code', 8)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_balance_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->index();
            $table->date('as_of');
            $table->string('side', 12);               // asset | liability
            $table->string('legacy_code', 8)->nullable();
            $table->string('name');
            $table->decimal('amount', 16, 4)->default(0);
            $table->unsignedSmallInteger('row_no');
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('acc_pl_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->index();
            $table->string('section', 12);            // income | expense | result
            $table->string('tax_code', 24)->nullable();
            $table->string('name');
            $table->decimal('amount', 16, 4)->default(0);
            $table->unsignedSmallInteger('row_no');
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_flow_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->index();
            $table->char('period', 7)->index();
            $table->string('section', 12)->index();   // income | payment | other | opening | closing
            $table->string('counterparty');
            $table->string('normalized')->index();
            $table->decimal('amount', 16, 4);
            $table->unsignedSmallInteger('row_no');
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_flow_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->index();
            $table->char('period', 7);
            $table->string('account');
            $table->decimal('amount', 16, 4)->default(0);
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('acc_ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->index();
            $table->string('legacy_code', 8)->index();  // 60, 61, 62 …
            $table->string('counterparty');
            $table->string('document')->nullable();     // null — kontragent cəmi sətri
            $table->decimal('debit', 16, 4)->default(0);
            $table->decimal('credit', 16, 4)->default(0);
            $table->unsignedSmallInteger('row_no');
            $table->foreignId('import_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('flow_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('normalized')->unique();
            $table->string('counterparty');
            $table->string('type', 16);                 // expense | cogs | tax | financing | capex | refund | revenue | fx | uncertain
            $table->string('expense_group')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $rows = [];
        foreach (ChartOfAccounts::accounts() as [$code, $name, $nature]) {
            $rows[] = [
                'code' => $code, 'name' => $name, 'nature' => $nature,
                'section' => ChartOfAccounts::sectionOf($code), 'group_code' => ChartOfAccounts::groupOf($code),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('chart_accounts')->insert($rows);
        $legacy = [];
        foreach (ChartOfAccounts::legacyMap() as $old => [$new, $note]) {
            $legacy[] = ['code' => (string) $old, 'chart_code' => $new, 'note' => $note ?: null, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('legacy_accounts')->insert($legacy);
    }

    public function down(): void
    {
        foreach (['flow_classifications', 'acc_ledger_lines', 'bank_flow_balances', 'bank_flow_lines', 'acc_pl_lines', 'acc_balance_lines', 'legacy_accounts', 'chart_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
