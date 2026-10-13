<?php

namespace App\Imports;

use App\Models\ImportBatch;
use App\Support\NameNormalizer;
use App\Support\Spreadsheet;

/**
 * 05_Qaytarmalar.xlsx → sales_returns.
 */
class ReturnsImporter extends Importer
{
    public function type(): string
    {
        return 'returns';
    }

    public function title(): string
    {
        return __('Qaytarmalar');
    }

    public function fileHint(): string
    {
        return '05_Qaytarmalar.xlsx';
    }

    public function tables(): array
    {
        return ['sales_returns'];
    }

    public function sheets(): array
    {
        return ['Qaytarmalar' => ['Tarix', 'Musteri_Kodu', 'Mal_Kodu', 'Miqdar', 'Yekun_Mebleg', 'Emeliyyat_Tipi']];
    }

    public static function opType(?string $value): string
    {
        return str_starts_with(NameNormalizer::normalize($value), 'daxili') ? 'internal' : 'customer';
    }

    protected function mapRow(string $sheet, array $row, int $rowNo): array
    {
        $errors = [];
        $date = Spreadsheet::date($row['Tarix'] ?? null);
        $productCode = Spreadsheet::code($row['Mal_Kodu'] ?? null);
        $qty = Spreadsheet::number($row['Miqdar'] ?? null);
        if ($date === null) {
            $errors[] = __('Tarix oxunmadı: :value', ['value' => (string) Spreadsheet::text($row['Tarix'] ?? null)]);
        }
        if ($productCode === null) {
            $errors[] = __(':column boşdur', ['column' => 'Mal_Kodu']);
        }
        if ($qty === null) {
            $errors[] = __(':column rəqəm deyil', ['column' => 'Miqdar']);
        }
        if ($errors !== []) {
            return self::result(null, $errors);
        }

        $customerCode = Spreadsheet::code($row['Musteri_Kodu'] ?? null);
        $customerName = Spreadsheet::text($row['Musteri_Adi'] ?? null);
        $originalName = Spreadsheet::text($row['Orijinal_Musteri_Adi'] ?? null);
        $opType = self::opType(Spreadsheet::text($row['Emeliyyat_Tipi'] ?? null));
        $productId = $this->context()->productId($productCode);
        $customerId = $this->context()->customerId($customerCode, [$customerName, $originalName]);
        $pending = [];
        if ($productId === null) {
            $pending[] = __('Tanınmayan mal kodu: :code', ['code' => $productCode]);
        }
        if ($customerId === null && $opType === 'customer') {
            $pending[] = __('Tanınmayan müştəri: :name', ['name' => $customerCode ?? $customerName ?? $originalName ?? '—']);
        }

        return self::result([
            'date' => $date->toDateString(),
            'period' => self::period($date),
            'return_no' => Spreadsheet::code($row['Qaytarma_No'] ?? null),
            'manager_name' => Spreadsheet::text($row['Menecer'] ?? null),
            'customer_id' => $customerId,
            'customer_code' => $customerCode,
            'customer_name' => $customerName,
            'original_customer_name' => $originalName,
            'product_id' => $productId,
            'product_code' => $productCode,
            'product_name' => Spreadsheet::text($row['Mal_Adi'] ?? null),
            'qty' => abs($qty),
            'price' => Spreadsheet::number($row['Qiymet'] ?? null) ?? 0,
            'discount_pct' => Spreadsheet::number($row['Endirim_Faizi'] ?? null) ?? 0,
            'net_amount' => abs(Spreadsheet::number($row['Yekun_Mebleg'] ?? null) ?? 0),
            'op_type' => $opType,
            'reason' => Spreadsheet::text($row['Sebeb'] ?? null),
            'note' => Spreadsheet::text($row['Qeyd'] ?? null),
            'status' => $pending === [] ? 'ok' : 'pending',
        ], [], $pending);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        foreach ($rows as &$row) {
            $row['manager_id'] = $this->context()->managerId($row['manager_name']);
            $row['import_batch_id'] = $batch->id;
        }
        unset($row);
        $this->insertChunked('sales_returns', $rows);
    }
}
