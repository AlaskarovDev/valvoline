<?php

namespace App\Imports;

use App\Models\ImportBatch;
use App\Support\NameNormalizer;
use App\Support\Spreadsheet;
use Illuminate\Support\Facades\DB;

/**
 * 04_Satislar.xlsx → sales_orders (Sifaris_No üzrə), sales_lines.
 * Təbii açar (row_hash): Sifaris_No + Mal_Kodu + Miqdar + Yekun_Mebleg + eyni açarın sifariş daxilində sırası.
 */
class SalesImporter extends Importer
{
    /** @var array<string, int> */
    private array $occurrences = [];

    public function type(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Satışlar';
    }

    public function fileHint(): string
    {
        return '04_Satislar.xlsx';
    }

    public function tables(): array
    {
        return ['sales_orders', 'sales_lines'];
    }

    public function sheets(): array
    {
        return ['Satis_Setirleri' => ['Tarix', 'Sifaris_No', 'Menecer', 'Musteri_Kodu', 'Musteri_Adi', 'Mal_Kodu', 'Miqdar', 'Qiymet', 'Yekun_Mebleg', 'Emeliyyat_Tipi']];
    }

    public static function opType(?string $value): ?string
    {
        $key = NameNormalizer::normalize($value);

        return match (true) {
            $key === 'satis' || $key === '' => 'sale',
            str_contains($key, 'hediyye') || str_contains($key, 'pulsuz') => 'gift',
            str_contains($key, 'daxili') => 'internal',
            str_starts_with($key, 'satis') => 'sale',
            default => null,
        };
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
        $errors = [];
        $date = Spreadsheet::date($row['Tarix'] ?? null);
        $orderNo = Spreadsheet::code($row['Sifaris_No'] ?? null);
        $productCode = Spreadsheet::code($row['Mal_Kodu'] ?? null);
        $qty = Spreadsheet::number($row['Miqdar'] ?? null);
        $net = Spreadsheet::number($row['Yekun_Mebleg'] ?? null);
        $opRaw = Spreadsheet::text($row['Emeliyyat_Tipi'] ?? null);
        $opType = self::opType($opRaw);
        if ($date === null) {
            $errors[] = 'Tarix oxunmadı: '.Spreadsheet::text($row['Tarix'] ?? null);
        }
        if ($orderNo === null) {
            $errors[] = 'Sifaris_No boşdur';
        }
        if ($productCode === null) {
            $errors[] = 'Mal_Kodu boşdur';
        }
        if ($qty === null) {
            $errors[] = 'Miqdar rəqəm deyil';
        }
        if ($opType === null) {
            $errors[] = "Naməlum Emeliyyat_Tipi: {$opRaw}";
        }
        if ($errors !== []) {
            return self::result(null, $errors);
        }

        $customerCode = Spreadsheet::code($row['Musteri_Kodu'] ?? null);
        $customerName = Spreadsheet::text($row['Musteri_Adi'] ?? null);
        $originalName = Spreadsheet::text($row['Orijinal_Musteri_Adi'] ?? null);
        $productId = $this->context()->productId($productCode);
        $customerId = $this->context()->customerId($customerCode, [$customerName, $originalName]);
        $pending = [];
        if ($productId === null) {
            $pending[] = "Tanınmayan mal kodu: {$productCode}";
        }
        if ($customerId === null) {
            $pending[] = 'Tanınmayan müştəri: '.($customerCode ?? $customerName ?? $originalName ?? '—');
        }

        $price = Spreadsheet::number($row['Qiymet'] ?? null) ?? 0;
        $amount = Spreadsheet::number($row['Mebleg'] ?? null) ?? $qty * $price;
        $net ??= $amount;
        $hashKey = implode('|', [$orderNo, $productCode, round($qty, 4), round($net, 2)]);
        $occurrence = $this->occurrences[$hashKey] = ($this->occurrences[$hashKey] ?? 0) + 1;

        return self::result([
            'date' => $date->toDateString(),
            'period' => self::period($date),
            'order_no' => $orderNo,
            'invoice_no' => Spreadsheet::code($row['Qaime_No'] ?? null),
            'manager_name' => Spreadsheet::text($row['Menecer'] ?? null),
            'customer_id' => $customerId,
            'customer_code' => $customerCode,
            'customer_name' => $customerName,
            'original_customer_name' => $originalName,
            'product_id' => $productId,
            'product_code' => $productCode,
            'product_name' => Spreadsheet::text($row['Mal_Adi'] ?? null),
            'qty' => $qty,
            'price' => $price,
            'amount' => $amount,
            'discount_pct' => Spreadsheet::number($row['Endirim_Faizi'] ?? null) ?? 0,
            'discount_price' => Spreadsheet::number($row['Endirimli_Qiymet'] ?? null) ?? $price,
            'net_amount' => $net,
            'op_type' => $opType,
            'note' => Spreadsheet::text($row['Qeyd'] ?? null),
            'row_hash' => sha1($hashKey.'|'.$occurrence),
            'status' => $pending === [] ? 'ok' : 'pending',
        ], [], $pending);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        $existing = [];
        if ($rows !== []) {
            // append rejimində (və ya əvvəlki partiyalar silinməyibsə) eyni təbii açarlı sətir təkrar yazılmır
            foreach (array_chunk(array_column($rows, 'row_hash'), 500) as $chunk) {
                foreach (DB::table('sales_lines')->whereIn('row_hash', $chunk)->pluck('row_hash') as $hash) {
                    $existing[$hash] = true;
                }
            }
        }

        $orders = [];
        $lines = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['row_hash']])) {
                $this->skipped++;

                continue;
            }
            $managerId = $this->context()->managerId($row['manager_name']);
            $row['manager_id'] = $managerId;
            $row['import_batch_id'] = $batch->id;
            $orders[$row['order_no']] ??= [
                'order_no' => $row['order_no'],
                'invoice_no' => $row['invoice_no'],
                'date' => $row['date'],
                'period' => $row['period'],
                'customer_id' => $row['customer_id'],
                'customer_code' => $row['customer_code'],
                'customer_name' => $row['customer_name'],
                'manager_id' => $managerId,
                'import_batch_id' => $batch->id,
            ];
            $lines[] = $row;
        }

        $existingOrders = DB::table('sales_orders')->whereIn('order_no', array_keys($orders) ?: ['-'])->pluck('id', 'order_no')->all();
        $this->insertChunked('sales_orders', array_values(array_diff_key($orders, $existingOrders)));
        $orderIds = [];
        foreach (array_chunk(array_keys($orders), 500) as $chunk) {
            $orderIds += DB::table('sales_orders')->whereIn('order_no', $chunk)->pluck('id', 'order_no')->all();
        }
        foreach ($lines as &$line) {
            $line['sales_order_id'] = $orderIds[$line['order_no']] ?? null;
        }
        unset($line);
        $this->insertChunked('sales_lines', $lines);
    }
}
