<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Çoxanbarlı model: əsas anbar + səyyar satış maşını (Cənub bölgəsi).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('warehouses')->insert([
            ['name' => 'Əsas anbar', 'code' => 'MAIN', 'is_main' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Cənub bölgəsi — Anbar 2', 'code' => 'A2', 'is_main' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('warehouses')->whereIn('code', ['MAIN', 'A2'])->delete();
    }
};
