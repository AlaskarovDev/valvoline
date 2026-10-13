<?php

namespace App\Imports;

use App\Models\ImportBatch;
use App\Support\NameNormalizer;
use App\Support\Spreadsheet;
use Illuminate\Support\Facades\DB;

/**
 * 02_Musteriler.xlsx → customers (kodla upsert), customer_aliases.
 */
class CustomersImporter extends Importer
{
    public function type(): string
    {
        return 'customers';
    }

    public function title(): string
    {
        return __('Müştərilər');
    }

    public function fileHint(): string
    {
        return '02_Musteriler.xlsx';
    }

    public function isMasterData(): bool
    {
        return true;
    }

    public function tables(): array
    {
        return ['customers', 'customer_aliases'];
    }

    public function sheets(): array
    {
        return [
            'Musteriler' => ['Musteri_Kodu', 'Musteri_Adi', 'Menecer', 'Musteri_Tipi'],
            'Ad_Uygunlasdirma' => ['Orijinal_Ad', 'Normallasdirilmis', 'Musteri_Kodu', 'Esas_Ad'],
        ];
    }

    protected function mapRow(string $sheet, array $row, int $rowNo): array
    {
        if ($sheet === 'Musteriler') {
            $code = Spreadsheet::code($row['Musteri_Kodu'] ?? null);
            if ($code === null) {
                return self::result(null, [__(':column boşdur', ['column' => 'Musteri_Kodu'])]);
            }
            $type = NameNormalizer::normalize(Spreadsheet::text($row['Musteri_Tipi'] ?? null));

            return self::result([
                'code' => $code,
                'name' => Spreadsheet::text($row['Musteri_Adi'] ?? null) ?? $code,
                'manager_name' => Spreadsheet::text($row['Menecer'] ?? null),
                'voen' => Spreadsheet::code($row['VOEN'] ?? null),
                'type' => str_starts_with($type, 'daxili') ? 'internal' : 'customer',
                'first_sale' => Spreadsheet::date($row['Ilk_Satis'] ?? null)?->toDateString(),
                'last_sale' => Spreadsheet::date($row['Son_Satis'] ?? null)?->toDateString(),
                'other_names' => Spreadsheet::text($row['Diger_Ad_Yazilislari'] ?? null),
            ]);
        }

        $original = Spreadsheet::text($row['Orijinal_Ad'] ?? null);
        if ($original === null) {
            return self::result(null, [__(':column boşdur', ['column' => 'Orijinal_Ad'])]);
        }
        $code = Spreadsheet::code($row['Musteri_Kodu'] ?? null);

        return self::result([
            'original_name' => $original,
            'normalized' => NameNormalizer::normalize($original),
            'file_normalized' => Spreadsheet::text($row['Normallasdirilmis'] ?? null),
            'customer_code' => $code,
            'main_name' => Spreadsheet::text($row['Esas_Ad'] ?? null),
            'method' => Spreadsheet::text($row['Uygunlasdirma_Usulu'] ?? null),
        ], [], $code === null ? [__('Alias üçün Musteri_Kodu yoxdur')] : []);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        $now = now();
        if ($sheet === 'Musteriler') {
            foreach ($rows as $row) {
                $values = [
                    'name' => $row['name'],
                    'manager_id' => $this->context()->managerId($row['manager_name']),
                    'voen' => $row['voen'],
                    'type' => $row['type'],
                    'first_sale' => $row['first_sale'],
                    'last_sale' => $row['last_sale'],
                    'other_names' => $row['other_names'],
                    'updated_at' => $now,
                ];
                if (DB::table('customers')->where('code', $row['code'])->exists()) {
                    DB::table('customers')->where('code', $row['code'])->update($values);
                } else {
                    DB::table('customers')->insert($values + ['code' => $row['code'], 'import_batch_id' => $batch->id, 'created_at' => $now]);
                }
            }

            return;
        }

        // Fayldan gələn aliaslar hər importda yenilənir; əl ilə uyğunlaşdırılanlar (import_batch_id = null) qalır.
        DB::table('customer_aliases')->whereNotNull('import_batch_id')->where('import_batch_id', '!=', $batch->id)->delete();
        $customerIds = DB::table('customers')->pluck('id', 'code')->all();
        $insert = [];
        foreach ($rows as $row) {
            $insert[] = [
                'original_name' => $row['original_name'],
                'normalized' => $row['normalized'],
                'customer_id' => $customerIds[$row['customer_code']] ?? null,
                'customer_code' => $row['customer_code'],
                'main_name' => $row['main_name'],
                'method' => $row['method'],
                'import_batch_id' => $batch->id,
            ];
        }
        $this->insertChunked('customer_aliases', $insert);
    }
}
