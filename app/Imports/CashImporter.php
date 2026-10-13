<?php

namespace App\Imports;

use App\Models\ExpenseCategory;
use App\Models\ImportBatch;
use App\Support\Spreadsheet;

/**
 * 08_Xercler.xlsx → expense_categories (kataloq, upsert), cash_transactions, cash_checkpoints.
 * Kateqoriya UI-dan redaktə olunubsa (user_edited) onun «Mənfəətə təsir» bayrağı fayldakını üstələyir.
 */
class CashImporter extends Importer
{
    public function type(): string
    {
        return 'cash';
    }

    public function title(): string
    {
        return __('Kassa / xərclər');
    }

    public function fileHint(): string
    {
        return '08_Xercler.xlsx';
    }

    public function tables(): array
    {
        return ['cash_transactions', 'cash_checkpoints'];
    }

    public function sheets(): array
    {
        return [
            'Kateqoriyalar' => ['Kateqoriya', 'Emeliyyat_Tipi', 'Menfeete_Tesir'],
            'Kassa_Emeliyyatlari' => ['Tarix', 'Kateqoriya', 'Medaxil', 'Mexaric', 'Emeliyyat_Tipi', 'Menfeete_Tesir', 'Istiqamet'],
            'Kassa_Qaliqlari' => ['Tarix', 'Kassa_Qaligi'],
        ];
    }

    protected function mapRow(string $sheet, array $row, int $rowNo): array
    {
        if ($sheet === 'Kateqoriyalar') {
            $name = Spreadsheet::text($row['Kateqoriya'] ?? null);
            if ($name === null) {
                return self::result(null, [__(':column boşdur', ['column' => 'Kateqoriya'])]);
            }

            return self::result([
                'name' => $name,
                'op_type' => Spreadsheet::text($row['Emeliyyat_Tipi'] ?? null) ?? 'Xərc',
                'affects_profit' => self::yes($row['Menfeete_Tesir'] ?? null),
                'row_count' => ($n = Spreadsheet::number($row['Setir_Sayi'] ?? null)) !== null ? (int) $n : null,
                'description' => Spreadsheet::text($row['Izah'] ?? null),
            ]);
        }

        $date = Spreadsheet::date($row['Tarix'] ?? null);
        if ($date === null) {
            return self::result(null, [__('Tarix oxunmadı: :value', ['value' => (string) Spreadsheet::text($row['Tarix'] ?? null)])]);
        }

        if ($sheet === 'Kassa_Qaliqlari') {
            $balance = Spreadsheet::number($row['Kassa_Qaligi'] ?? null);
            if ($balance === null) {
                return self::result(null, [__(':column rəqəm deyil', ['column' => 'Kassa_Qaligi'])]);
            }

            return self::result([
                'date' => $date->toDateString(),
                'description' => Spreadsheet::text($row['Tesvir'] ?? null),
                'balance' => $balance,
            ]);
        }

        return self::result([
            'date' => $date->toDateString(),
            'period' => self::period($date),
            'category_name' => Spreadsheet::text($row['Kateqoriya'] ?? null) ?? 'Təsnif edilməyib',
            'subcategory' => Spreadsheet::text($row['Alt_Kateqoriya'] ?? null),
            'description' => Spreadsheet::text($row['Tesvir'] ?? null),
            'income' => Spreadsheet::number($row['Medaxil'] ?? null) ?? 0,
            'expense' => Spreadsheet::number($row['Mexaric'] ?? null) ?? 0,
            'op_type' => Spreadsheet::text($row['Emeliyyat_Tipi'] ?? null),
            'affects_profit_raw' => self::yes($row['Menfeete_Tesir'] ?? null),
            'direction' => Spreadsheet::text($row['Istiqamet'] ?? null),
            'category_source' => Spreadsheet::text($row['Kateqoriya_Menbeyi'] ?? null),
            'note' => Spreadsheet::text($row['Qeyd'] ?? null),
            'original_row' => Spreadsheet::text($row['Orijinal_Setir'] ?? null),
        ]);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        if ($sheet === 'Kateqoriyalar') {
            foreach ($rows as $row) {
                $category = ExpenseCategory::query()->firstOrNew(['name' => $row['name']]);
                if (! $category->exists) {
                    $category->import_batch_id = $batch->id;
                }
                if (! $category->user_edited) {
                    $category->op_type = $row['op_type'];
                    $category->affects_profit = $row['affects_profit'];
                }
                $category->row_count = $row['row_count'];
                $category->description = $row['description'];
                $category->save();
            }

            return;
        }

        if ($sheet === 'Kassa_Qaliqlari') {
            $this->insertChunked('cash_checkpoints', array_map(fn ($r) => [
                'date' => $r['date'],
                'description' => $r['description'],
                'balance' => $r['balance'],
                'import_batch_id' => $batch->id,
            ], $rows));

            return;
        }

        $categories = ExpenseCategory::query()->get()->keyBy('name');
        foreach ($rows as &$row) {
            $category = $categories[$row['category_name']] ?? null;
            if ($category === null) {
                $category = ExpenseCategory::query()->create([
                    'name' => $row['category_name'],
                    'op_type' => $row['op_type'] ?? 'Xərc',
                    'affects_profit' => $row['affects_profit_raw'],
                    'import_batch_id' => $batch->id,
                ]);
                $categories[$row['category_name']] = $category;
            }
            $row['expense_category_id'] = $category->id;
            $row['affects_profit'] = $category->user_edited ? $category->affects_profit : $row['affects_profit_raw'];
            if ($category->user_edited) {
                $row['op_type'] = $category->op_type; // UI-da dəyişdirilmiş tip (məs. «Təsisçiyə ödəniş») təkrar importda da qalır
            }
            $row['import_batch_id'] = $batch->id;
        }
        unset($row);
        $this->insertChunked('cash_transactions', $rows);
    }
}
