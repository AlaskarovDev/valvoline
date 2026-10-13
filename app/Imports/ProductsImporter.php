<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ProductGroup;
use App\Support\Spreadsheet;
use Illuminate\Support\Facades\DB;

/**
 * 01_Mallar.xlsx → products, product_groups, categories (kodla upsert).
 */
class ProductsImporter extends Importer
{
    public function type(): string
    {
        return 'products';
    }

    public function title(): string
    {
        return __('Mallar');
    }

    public function fileHint(): string
    {
        return '01_Mallar.xlsx';
    }

    public function isMasterData(): bool
    {
        return true;
    }

    public function tables(): array
    {
        return ['products'];
    }

    public function sheets(): array
    {
        return ['Mallar' => ['Mal_Kodu', 'Mal_Adi', 'Mehsul_Qrupu', 'Kateqoriya', 'Hecm_Litr', 'Satis_Qiymeti']];
    }

    protected function mapRow(string $sheet, array $row, int $rowNo): array
    {
        $code = Spreadsheet::code($row['Mal_Kodu'] ?? null);
        $name = Spreadsheet::text($row['Mal_Adi'] ?? null);
        if ($code === null) {
            return self::result(null, [__(':column boşdur', ['column' => 'Mal_Kodu'])]);
        }

        return self::result([
            'code' => $code,
            'name' => $name ?? $code,
            'group_name' => Spreadsheet::text($row['Mehsul_Qrupu'] ?? null) ?? ($name ?? $code),
            'category_name' => Spreadsheet::text($row['Kateqoriya'] ?? null),
            'group_code_count' => (int) (Spreadsheet::number($row['Qrupda_Kod_Sayi'] ?? null) ?? 1),
            'series' => Spreadsheet::text($row['Seriya'] ?? null),
            'viscosity' => Spreadsheet::text($row['Ozluluk'] ?? null),
            'volume_l' => Spreadsheet::number($row['Hecm_Litr'] ?? null) ?? 0,
            'pack_qty' => ($q = Spreadsheet::number($row['Qutuda_Eded'] ?? null)) !== null ? (int) $q : null,
            'pack_type' => Spreadsheet::text($row['Qablasdirma_Tipi'] ?? null),
            'sale_price' => Spreadsheet::number($row['Satis_Qiymeti'] ?? null) ?? 0,
            'file_last_cost' => Spreadsheet::number($row['Son_Maya_Deyeri'] ?? null),
            'file_avg_cost' => Spreadsheet::number($row['Orta_Maya_Deyeri'] ?? null),
            'old_codes' => Spreadsheet::text($row['Kohne_Kod_Yazilislari'] ?? null),
        ]);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        $categories = [];
        $groups = [];
        $now = now();
        foreach ($rows as $row) {
            $categoryId = null;
            if ($row['category_name'] !== null) {
                $categoryId = $categories[$row['category_name']] ??= Category::query()->firstOrCreate(['name' => $row['category_name']])->id;
            }
            $groupId = $groups[$row['group_name']] ??= ProductGroup::query()->updateOrCreate(
                ['name' => $row['group_name']],
                ['series' => $row['series'], 'category_id' => $categoryId],
            )->id;

            $values = [
                'name' => $row['name'],
                'product_group_id' => $groupId,
                'category_id' => $categoryId,
                'group_code_count' => $row['group_code_count'],
                'series' => $row['series'],
                'viscosity' => $row['viscosity'],
                'volume_l' => $row['volume_l'],
                'pack_qty' => $row['pack_qty'],
                'pack_type' => $row['pack_type'],
                'sale_price' => $row['sale_price'],
                'file_last_cost' => $row['file_last_cost'],
                'file_avg_cost' => $row['file_avg_cost'],
                'old_codes' => $row['old_codes'],
                'updated_at' => $now,
            ];
            if (DB::table('products')->where('code', $row['code'])->exists()) {
                DB::table('products')->where('code', $row['code'])->update($values);
            } else {
                DB::table('products')->insert($values + ['code' => $row['code'], 'import_batch_id' => $batch->id, 'created_at' => $now]);
            }
        }
    }
}
