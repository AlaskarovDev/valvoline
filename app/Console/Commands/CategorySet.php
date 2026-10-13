<?php

namespace App\Console\Commands;

use App\Models\ExpenseCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Xərc kateqoriyasının tipini / «Mənfəətə təsir» bayrağını dəyişir (UI-dakı redaktə ilə eyni məntiq).
 */
class CategorySet extends Command
{
    protected $signature = 'category:set {name} {--type= : Xərc | Mədaxil | Təsisçiyə ödəniş | Köçürmə} {--profit= : 1 və ya 0}';

    protected $description = 'Xərc kateqoriyasının tipini və mənfəətə təsir bayrağını dəyişir';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $category = ExpenseCategory::query()->where('name', $name)->first()
            ?? ExpenseCategory::query()->where('name', 'like', '%'.$name.'%')->first();
        if ($category === null) {
            $this->error("Kateqoriya tapılmadı: {$name}");

            return self::FAILURE;
        }
        $type = $this->option('type') ?: $category->op_type;
        $profit = $this->option('profit') !== null ? (bool) (int) $this->option('profit') : $category->affects_profit;

        DB::transaction(function () use ($category, $type, $profit) {
            $category->fill(['op_type' => $type, 'affects_profit' => $profit, 'user_edited' => true])->save();
            DB::table('cash_transactions')->where('expense_category_id', $category->id)
                ->update(['op_type' => $type, 'affects_profit' => $profit]);
        });
        $rows = DB::table('cash_transactions')->where('expense_category_id', $category->id)->count();
        $this->info("«{$category->name}»: tip = {$type}, mənfəətə təsir = ".($profit ? 'Bəli' : 'Xeyr')." ({$rows} əməliyyat).");

        return self::SUCCESS;
    }
}
