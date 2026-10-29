<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * İmport edilmiş sifariş nömrələri «№3930» şəklindədirsə, sifariş qaydası «№{SEQ}» olsun ki, seriya №3931-dən davam etsin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('numbering_rules')->where('key', 'sales_order')->exists()) {
            return;
        }
        $prefixed = DB::table('sales_orders')->where('order_no', 'like', '№%')->count();
        $plain = DB::table('sales_orders')->pluck('order_no')->filter(fn ($no) => ctype_digit((string) $no))->count();
        if ($prefixed > $plain) {
            DB::table('numbering_rules')->insert(['key' => 'sales_order', 'pattern' => '№{SEQ}', 'mode' => 'auto_editable', 'reset' => 'never', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('numbering_rules')->where('key', 'sales_order')->where('pattern', '№{SEQ}')->delete();
    }
};
