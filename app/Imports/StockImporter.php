<?php

namespace App\Imports;

use App\Models\ImportBatch;
use App\Models\Warehouse;
use App\Support\NameNormalizer;
use App\Support\Spreadsheet;

/**
 * 07_Anbar.xlsx → stock_adjustments, stock_transfers. «Cari_Qaliq_Yoxlama» vərəqi import EDİLMİR.
 */
class StockImporter extends Importer
{
    public function type(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return __('Anbar (sayım, transfer)');
    }

    public function fileHint(): string
    {
        return '07_Anbar.xlsx';
    }

    public function tables(): array
    {
        return ['stock_adjustments', 'stock_transfers'];
    }

    public function sheets(): array
    {
        return [
            'Sayim_Duzelisleri' => ['Tarix', 'Mal_Kodu', 'Duzelis_Miqdari'],
            'Anbar_Transferleri' => ['Tarix', 'Istiqamet', 'Haradan', 'Haraya', 'Mal_Kodu', 'Miqdar'],
        ];
    }

    protected function mapRow(string $sheet, array $row, int $rowNo): array
    {
        $errors = [];
        $date = Spreadsheet::date($row['Tarix'] ?? null);
        $code = Spreadsheet::code($row['Mal_Kodu'] ?? null);
        $qty = Spreadsheet::number($row[$sheet === 'Sayim_Duzelisleri' ? 'Duzelis_Miqdari' : 'Miqdar'] ?? null);
        if ($date === null) {
            $errors[] = __('Tarix oxunmadı: :value', ['value' => (string) Spreadsheet::text($row['Tarix'] ?? null)]);
        }
        if ($code === null) {
            $errors[] = __(':column boşdur', ['column' => 'Mal_Kodu']);
        }
        if ($qty === null) {
            $errors[] = __(':column rəqəm deyil', ['column' => 'Miqdar']);
        }
        if ($errors !== []) {
            return self::result(null, $errors);
        }
        $productId = $this->context()->productId($code);
        $pending = $productId === null ? [__('Tanınmayan mal kodu: :code', ['code' => $code])] : [];
        $common = [
            'date' => $date->toDateString(),
            'period' => self::period($date),
            'product_id' => $productId,
            'product_code' => $code,
            'product_name' => Spreadsheet::text($row['Mal_Adi'] ?? null),
            'status' => $pending === [] ? 'ok' : 'pending',
        ];

        if ($sheet === 'Sayim_Duzelisleri') {
            return self::result($common + [
                'computed_qty' => Spreadsheet::number($row['Hesablanan_Qaliq'] ?? null),
                'counted_qty' => Spreadsheet::number($row['Sayim_Qaligi'] ?? null),
                'qty' => $qty,
                'reason' => Spreadsheet::text($row['Sebeb'] ?? null),
            ], [], $pending);
        }

        $direction = str_starts_with(NameNormalizer::normalize(Spreadsheet::text($row['Istiqamet'] ?? null)), 'giris') ? 'in' : 'out';

        return self::result($common + [
            'doc_no' => Spreadsheet::code($row['Sened_No'] ?? null),
            'direction' => $direction,
            'from_name' => Spreadsheet::text($row['Haradan'] ?? null),
            'to_name' => Spreadsheet::text($row['Haraya'] ?? null),
            'qty' => abs($qty),
            'note' => Spreadsheet::text($row['Qeyd'] ?? null),
        ], [], $pending);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        $context = $this->context();
        $main = $context->mainWarehouseId();
        $second = (int) (Warehouse::query()->where('is_main', false)->orderBy('id')->value('id') ?? $main);
        foreach ($rows as &$row) {
            $row['import_batch_id'] = $batch->id;
            if ($sheet === 'Sayim_Duzelisleri') {
                $row['warehouse_id'] = $main;

                continue;
            }
            // Haradan/Haraya boşdursa: Çıxış = əsas → səyyar, Giriş = səyyar → əsas
            $defaultFrom = $row['direction'] === 'out' ? $main : $second;
            $defaultTo = $row['direction'] === 'out' ? $second : $main;
            $row['from_warehouse_id'] = $context->warehouseId($row['from_name'], $defaultFrom);
            $row['to_warehouse_id'] = $context->warehouseId($row['to_name'], $defaultTo);
        }
        unset($row);
        $this->insertChunked($sheet === 'Sayim_Duzelisleri' ? 'stock_adjustments' : 'stock_transfers', $rows);
    }
}
