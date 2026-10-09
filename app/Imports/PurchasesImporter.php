<?php

namespace App\Imports;

use App\Models\ImportBatch;
use App\Support\NameNormalizer;
use App\Support\Spreadsheet;
use Illuminate\Support\Facades\DB;

/**
 * 03_Idxal_Alislar.xlsx → purchase_batches, purchase_lines. Partiya_No = 0 açılış qalığıdır.
 */
class PurchasesImporter extends Importer
{
    public function type(): string
    {
        return 'purchases';
    }

    public function title(): string
    {
        return 'İdxal / alışlar';
    }

    public function fileHint(): string
    {
        return '03_Idxal_Alislar.xlsx';
    }

    public function tables(): array
    {
        return ['purchase_batches', 'purchase_lines'];
    }

    public function sheets(): array
    {
        return [
            'Partiyalar' => ['Partiya_No', 'Partiya_Adi', 'Tarix'],
            'Idxal_Setirleri' => ['Partiya_No', 'Tarix', 'Mal_Kodu', 'Miqdar_Eded', 'Maya_Deyeri_Vahid'],
        ];
    }

    protected function mapRow(string $sheet, array $row, int $rowNo): array
    {
        $batchNo = Spreadsheet::number($row['Partiya_No'] ?? null);
        $date = Spreadsheet::date($row['Tarix'] ?? null);
        if ($sheet === 'Partiyalar') {
            if ($batchNo === null) {
                return self::result(null, ['Partiya_No boşdur']);
            }

            return self::result([
                'batch_no' => (int) $batchNo,
                'name' => Spreadsheet::text($row['Partiya_Adi'] ?? null),
                'date' => $date?->toDateString(),
                'date_source' => Spreadsheet::text($row['Tarix_Menbeyi'] ?? null),
                'sku_count' => (int) (Spreadsheet::number($row['Mal_Cesidi'] ?? null) ?? 0),
                'total_qty' => Spreadsheet::number($row['Cem_Eded'] ?? null) ?? 0,
                'total_cost' => Spreadsheet::number($row['Cem_Maya_AZN'] ?? null) ?? 0,
            ]);
        }

        $errors = [];
        $code = Spreadsheet::code($row['Mal_Kodu'] ?? null);
        $qty = Spreadsheet::number($row['Miqdar_Eded'] ?? null);
        $unitCost = Spreadsheet::number($row['Maya_Deyeri_Vahid'] ?? null);
        if ($batchNo === null) {
            $errors[] = 'Partiya_No boşdur';
        }
        if ($date === null) {
            $errors[] = 'Tarix oxunmadı: '.Spreadsheet::text($row['Tarix'] ?? null);
        }
        if ($code === null) {
            $errors[] = 'Mal_Kodu boşdur';
        }
        if ($qty === null) {
            $errors[] = 'Miqdar_Eded rəqəm deyil';
        }
        if ($errors !== []) {
            return self::result(null, $errors);
        }
        $note = Spreadsheet::text($row['Qeyd'] ?? null);
        $productId = $this->context()->productId($code);
        $total = Spreadsheet::number($row['Cem_Maya'] ?? null);

        return self::result([
            'batch_no' => (int) $batchNo,
            'date' => $date->toDateString(),
            'period' => self::period($date),
            'product_id' => $productId,
            'product_code' => $code,
            'product_name' => Spreadsheet::text($row['Mal_Adi'] ?? null),
            'qty' => $qty,
            'unit_cost' => $unitCost ?? ($total !== null && $qty != 0 ? $total / $qty : 0),
            'total_cost' => $total ?? $qty * ($unitCost ?? 0),
            'orig_qty' => Spreadsheet::number($row['Orijinal_Miqdar'] ?? null),
            'orig_unit' => Spreadsheet::text($row['Orijinal_Vahid'] ?? null),
            'note' => $note,
            'is_estimated' => str_contains(NameNormalizer::normalize($note), 'texmini'),
            'status' => $productId === null ? 'pending' : 'ok',
        ], [], $productId === null ? ["Tanınmayan mal kodu: {$code}"] : []);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        if ($sheet === 'Partiyalar') {
            $this->insertChunked('purchase_batches', array_map(fn ($r) => [
                'batch_no' => $r['batch_no'],
                'name' => $r['name'],
                'date' => $r['date'],
                'date_source' => $r['date_source'],
                'sku_count' => $r['sku_count'],
                'total_qty' => $r['total_qty'],
                'total_cost' => $r['total_cost'],
                'import_batch_id' => $batch->id,
            ], $rows));

            return;
        }

        $batchIds = DB::table('purchase_batches')->where('import_batch_id', $batch->id)->pluck('id', 'batch_no')->all();
        foreach ($rows as &$row) {
            $row['purchase_batch_id'] = $batchIds[$row['batch_no']] ?? null;
            $row['import_batch_id'] = $batch->id;
        }
        unset($row);
        $this->insertChunked('purchase_lines', $rows);
    }
}
