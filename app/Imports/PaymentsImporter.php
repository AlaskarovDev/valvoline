<?php

namespace App\Imports;

use App\Models\ImportBatch;
use App\Support\Spreadsheet;
use Illuminate\Support\Facades\DB;

/**
 * 06_Odenisler.xlsx → payments. Mənfi məbləğ = müştəriyə geri ödəniş.
 * Təbii açar (row_hash): Tarix + Musteri_Kodu + Cem_Odenis + Qeyd + eyni açarın sırası.
 */
class PaymentsImporter extends Importer
{
    /** @var array<string, int> */
    private array $occurrences = [];

    public function type(): string
    {
        return 'payments';
    }

    public function title(): string
    {
        return __('Ödənişlər');
    }

    public function fileHint(): string
    {
        return '06_Odenisler.xlsx';
    }

    public function tables(): array
    {
        return ['payments'];
    }

    public function sheets(): array
    {
        return ['Odenisler' => ['Tarix', 'Musteri_Kodu', 'Musteri_Adi', 'Cem_Odenis']];
    }

    public function preview(string $path): array
    {
        $this->occurrences = [];

        return parent::preview($path);
    }

    public function import(string $path, ImportBatch $batch, string $mode = 'replace'): ImportBatch
    {
        $this->occurrences = [];

        return parent::import($path, $batch, $mode);
    }

    protected function mapRow(string $sheet, array $row, int $rowNo): array
    {
        $date = Spreadsheet::date($row['Tarix'] ?? null);
        $cash = Spreadsheet::number($row['Nagd'] ?? null) ?? 0;
        $bank = Spreadsheet::number($row['Bank'] ?? null) ?? 0;
        $total = Spreadsheet::number($row['Cem_Odenis'] ?? null) ?? ($cash + $bank);
        if ($date === null) {
            return self::result(null, [__('Tarix oxunmadı: :value', ['value' => (string) Spreadsheet::text($row['Tarix'] ?? null)])]);
        }

        $customerCode = Spreadsheet::code($row['Musteri_Kodu'] ?? null);
        $customerName = Spreadsheet::text($row['Musteri_Adi'] ?? null);
        $originalName = Spreadsheet::text($row['Orijinal_Musteri_Adi'] ?? null);
        $note = Spreadsheet::text($row['Qeyd'] ?? null);
        $customerId = $this->context()->customerId($customerCode, [$customerName, $originalName]);
        $pending = $customerId === null ? [__('Tanınmayan müştəri: :name', ['name' => $customerCode ?? $customerName ?? $originalName ?? '—'])] : [];
        $hashKey = implode('|', [$date->toDateString(), $customerCode ?? $customerName, round($total, 2), $note]);
        $occurrence = $this->occurrences[$hashKey] = ($this->occurrences[$hashKey] ?? 0) + 1;

        return self::result([
            'date' => $date->toDateString(),
            'period' => self::period($date),
            'customer_id' => $customerId,
            'customer_code' => $customerCode,
            'customer_name' => $customerName,
            'original_customer_name' => $originalName,
            'manager_name' => Spreadsheet::text($row['Menecer'] ?? null),
            'cash' => $cash,
            'bank' => $bank,
            'total' => $total,
            'received_by' => Spreadsheet::text($row['Tehvil_Alan'] ?? null),
            'note' => $note,
            'row_hash' => sha1($hashKey.'|'.$occurrence),
            'status' => $pending === [] ? 'ok' : 'pending',
        ], [], $pending);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        $existing = [];
        foreach (array_chunk(array_column($rows, 'row_hash'), 500) as $chunk) {
            foreach (DB::table('payments')->whereIn('row_hash', $chunk)->pluck('row_hash') as $hash) {
                $existing[$hash] = true;
            }
        }
        $insert = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['row_hash']])) {
                $this->skipped++;

                continue;
            }
            $row['manager_id'] = $this->context()->managerId($row['manager_name']);
            $row['import_batch_id'] = $batch->id;
            $insert[] = $row;
        }
        $this->insertChunked('payments', $insert);
    }
}
