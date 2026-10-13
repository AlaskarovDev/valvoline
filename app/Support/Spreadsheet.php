<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Options as ReaderOptions;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Axınla (streaming) Excel oxuma/yazma — 15 000+ sətirlik fayllar yaddaşı doldurmadan oxunur.
 * Başlıq həmişə 1-ci sətirdədir.
 */
class Spreadsheet
{
    /**
     * @return array<string, array{headers: list<string>, rows: int}>
     */
    public static function inspect(string $path): array
    {
        $reader = self::open($path);
        $result = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $headers = [];
            $rows = 0;
            foreach ($sheet->getRowIterator() as $index => $row) {
                if ($headers === []) {
                    $headers = array_map(fn ($v) => trim((string) self::scalar($v)), $row->toArray());

                    continue;
                }
                if (! self::isBlank($row->toArray())) {
                    $rows++;
                }
            }
            $result[$sheet->getName()] = ['headers' => $headers, 'rows' => $rows];
        }
        $reader->close();

        return $result;
    }

    /**
     * Vərəqin sətirlərini başlıqla açarlanmış massiv kimi qaytarır.
     *
     * @return Generator<int, array<string, mixed>> açar = Excel sətir nömrəsi (2-dən başlayır)
     */
    public static function rows(string $path, string $sheetName): Generator
    {
        $reader = self::open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (mb_strtolower(trim($sheet->getName())) !== mb_strtolower(trim($sheetName))) {
                    continue;
                }
                $headers = null;
                $excelRow = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $excelRow++;
                    $values = $row->toArray();
                    if ($headers === null) {
                        $headers = array_map(fn ($v) => trim((string) self::scalar($v)), $values);

                        continue;
                    }
                    if (self::isBlank($values)) {
                        continue;
                    }
                    $assoc = [];
                    foreach ($headers as $i => $header) {
                        if ($header === '') {
                            continue;
                        }
                        $assoc[$header] = $values[$i] ?? null;
                    }
                    yield $excelRow => $assoc;
                }

                return;
            }
            throw new RuntimeException(__('«:sheet» vərəqi tapılmadı.', ['sheet' => $sheetName]));
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  array<string, array{headers: list<string>, rows: iterable<array<int, mixed>>}>  $sheets
     */
    public static function write(string $path, array $sheets): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true)->withBackgroundColor('E8EEF5');
        $first = true;
        foreach ($sheets as $name => $sheet) {
            if (! $first) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $first = false;
            $writer->getCurrentSheet()->setName(mb_substr($name, 0, 31));
            $writer->addRow(Row::fromValuesWithStyle($sheet['headers'], $bold));
            foreach ($sheet['rows'] as $values) {
                $writer->addRow(Row::fromValues(array_map(self::cellValue(...), array_values($values))));
            }
        }
        $writer->close();
    }

    /**
     * Kodlar həmişə mətn: 874738.0 → "874738", " VE11258 " → "VE11258".
     */
    public static function code(mixed $value): ?string
    {
        $value = self::scalar($value);
        if ($value === null) {
            return null;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }
        $value = trim((string) $value);
        if (preg_match('/^\d+\.0+$/', $value)) {
            $value = substr($value, 0, strpos($value, '.'));
        }

        return $value === '' ? null : $value;
    }

    public static function text(mixed $value): ?string
    {
        $value = self::scalar($value);
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('d.m.Y');
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Rəqəm: 1234.5, "1 234,50", "1,234.50", "30%", "" → null.
     */
    public static function number(mixed $value): ?float
    {
        $value = self::scalar($value);
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        $string = str_replace(["\u{00A0}", "\u{202F}", ' ', '₼', 'AZN'], '', (string) $value);
        $percent = str_ends_with($string, '%');
        $string = rtrim($string, '%');
        if (str_contains($string, ',') && str_contains($string, '.')) {
            $string = strrpos($string, ',') > strrpos($string, '.')
                ? str_replace(['.', ','], ['', '.'], $string)
                : str_replace(',', '', $string);
        } else {
            $string = str_replace(',', '.', $string);
        }
        $string = str_replace('−', '-', $string);
        if (! is_numeric($string)) {
            return null;
        }

        return $percent ? (float) $string / 100 : (float) $string;
    }

    /**
     * Tarix: Excel tarix xanası, seriya nömrəsi (45336), "13.02.2024", "2024-02-13".
     */
    public static function date(mixed $value): ?Carbon
    {
        $value = self::scalar($value);
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', $value))) {
            $serial = (float) $value;

            return Carbon::create(1899, 12, 30)->addDays((int) floor($serial))->startOfDay();
        }
        $value = trim((string) $value);
        foreach (['d.m.Y', 'd.m.Y H:i', 'd.m.Y H:i:s', 'Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd.m.y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return Carbon::instance($date)->startOfDay();
            }
        }

        return null;
    }

    private static function open(string $path): Reader
    {
        if (! is_file($path)) {
            throw new RuntimeException(__('Fayl tapılmadı').': '.$path);
        }
        $reader = new Reader(new ReaderOptions(SHOULD_FORMAT_DATES: false));
        $reader->open($path);

        return $reader;
    }

    private static function scalar(mixed $value): mixed
    {
        if ($value instanceof \DateInterval) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $value;
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (self::scalar($value) !== null) {
                return false;
            }
        }

        return true;
    }

    private static function cellValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('d.m.Y');
        }
        if (is_bool($value)) {
            return $value ? __('Bəli') : __('Xeyr');
        }

        return $value;
    }
}
