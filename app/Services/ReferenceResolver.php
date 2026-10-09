<?php

namespace App\Services;

use App\Imports\ImportContext;
use Illuminate\Support\Facades\DB;

/**
 * «Gözləmədə» sətirləri yenidən bağlayır: yeni mal / müştəri / alias əlavə olunandan sonra
 * product_id və customer_id-ni kod və ad uyğunlaşdırması ilə doldurur və status-u yeniləyir.
 */
class ReferenceResolver
{
    /**
     * @return array<string, int> cədvəl => hələ də gözləmədə qalan sətir sayı
     */
    public function run(): array
    {
        $context = new ImportContext;

        foreach (['purchase_lines', 'sales_lines', 'sales_returns', 'stock_adjustments', 'stock_transfers'] as $table) {
            $codes = DB::table($table)->whereNull('product_id')->distinct()->pluck('product_code');
            foreach ($codes as $code) {
                if (($id = $context->productId($code)) !== null) {
                    DB::table($table)->whereNull('product_id')->where('product_code', $code)->update(['product_id' => $id]);
                }
            }
        }

        foreach (['sales_lines', 'sales_returns', 'payments', 'sales_orders'] as $table) {
            $columns = $table === 'sales_orders' ? ['customer_code', 'customer_name'] : ['customer_code', 'customer_name', 'original_customer_name'];
            $groups = DB::table($table)->whereNull('customer_id')->select($columns)->distinct()->get();
            foreach ($groups as $group) {
                $id = $context->customerId($group->customer_code, [$group->customer_name, $group->original_customer_name ?? null]);
                if ($id === null) {
                    continue;
                }
                $query = DB::table($table)->whereNull('customer_id');
                foreach ($columns as $column) {
                    $group->{$column} === null ? $query->whereNull($column) : $query->where($column, $group->{$column});
                }
                $query->update(['customer_id' => $id]);
            }
        }

        // status: mal tələb olunur; müştəri — satış, ödəniş və müştəri qaytarması üçün
        DB::table('purchase_lines')->update(['status' => DB::raw("CASE WHEN product_id IS NULL THEN 'pending' ELSE 'ok' END")]);
        DB::table('stock_adjustments')->update(['status' => DB::raw("CASE WHEN product_id IS NULL THEN 'pending' ELSE 'ok' END")]);
        DB::table('stock_transfers')->update(['status' => DB::raw("CASE WHEN product_id IS NULL THEN 'pending' ELSE 'ok' END")]);
        DB::table('sales_lines')->update(['status' => DB::raw("CASE WHEN product_id IS NULL OR customer_id IS NULL THEN 'pending' ELSE 'ok' END")]);
        DB::table('sales_returns')->update(['status' => DB::raw("CASE WHEN product_id IS NULL OR (customer_id IS NULL AND op_type = 'customer') THEN 'pending' ELSE 'ok' END")]);
        DB::table('payments')->update(['status' => DB::raw("CASE WHEN customer_id IS NULL THEN 'pending' ELSE 'ok' END")]);

        $pending = [];
        foreach (['purchase_lines', 'sales_lines', 'sales_returns', 'payments', 'stock_adjustments', 'stock_transfers'] as $table) {
            $pending[$table] = DB::table($table)->where('status', 'pending')->count();
        }

        return $pending;
    }
}
