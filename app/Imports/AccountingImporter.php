<?php

namespace App\Imports;

use App\Models\ImportBatch;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * «Balans / MZ / Cash Flow» mühasibat faylı (1C-dən, .xls və ya .xlsx):
 *   BALANS     — köhnə hesab nömrələri ilə ilin sonuna balans (aktiv / passiv)
 *   MZ         — vergi bəyannaməsi kodları ilə gəlir və xərclər
 *   Cash Flow  — aylar üzrə kontragentlərə görə mədaxil / ödəniş, ay sonu qalıqları
 *   60, 61, 62 — hesab üzrə dövriyyə qalıq cədvəlləri (kontragent / sənəd)
 * Eyni il yenidən yüklənəndə həmin ilin məlumatı əvəzlənir.
 */
class AccountingImporter extends Importer
{
    public const TABLES = ['acc_balance_lines', 'acc_pl_lines', 'bank_flow_lines', 'bank_flow_balances', 'acc_ledger_lines'];

    /** @var array<string, mixed>|null */
    private ?array $parsed = null;

    private ?string $parsedPath = null;

    public function type(): string
    {
        return 'accounting';
    }

    public function title(): string
    {
        return __('Balans / MZ / Cash Flow (mühasibat)');
    }

    public function fileHint(): string
    {
        return '09_Balans_MZ_CashFlow.xls';
    }

    public function sheets(): array
    {
        return ['BALANS' => ['Hesab №-si'], 'MZ' => [], 'Cash Flow' => []];
    }

    public function tables(): array
    {
        return self::TABLES;
    }

    protected function mapRow(string $sheet, array $row, int $rowNo): array
    {
        return self::result($row);
    }

    protected function persist(string $sheet, array $rows, ImportBatch $batch): void
    {
        // istifadə olunmur — import() bütün faylı birdən yazır
    }

    public function preview(string $path): array
    {
        $data = $this->parse($path);
        $result = ['sheets' => [], 'errors' => [], 'pending' => [], 'ok' => true];
        foreach ($data['errors'] as $error) {
            $result['errors'][] = ['sheet' => $error[0], 'row' => $error[1], 'message' => $error[2]];
        }
        if ($data['year'] === null) {
            $result['ok'] = false;
            $result['errors'][] = ['sheet' => 'BALANS', 'row' => 0, 'message' => __('Hesabat ili təyin olunmadı (BALANS başlığında və ya Cash Flow aylarında tarix yoxdur).')];
        }
        $sheets = [
            'BALANS' => [['side', 'legacy_code', 'name', 'amount'], $data['balance']],
            'MZ' => [['section', 'tax_code', 'name', 'amount'], $data['pl']],
            'Cash Flow' => [['period', 'section', 'counterparty', 'amount'], $data['flows']],
            __('Hesab qalıqları (60/61/62…)') => [['legacy_code', 'counterparty', 'document', 'debit', 'credit'], $data['ledger']],
        ];
        foreach ($sheets as $name => [$headers, $rows]) {
            $result['sheets'][$name] = [
                'headers' => $headers,
                'missing' => [],
                'rows' => count($rows),
                'sample' => array_map(fn ($r) => array_intersect_key($r, array_flip($headers)) + ['_status' => 'ok'], array_slice($rows, 0, self::PREVIEW_ROWS)),
                'pending' => 0,
                'errors' => 0,
            ];
        }
        if ($data['balance'] === [] && $data['flows'] === []) {
            $result['ok'] = false;
            $result['errors'][] = ['sheet' => '', 'row' => 0, 'message' => __('Faylda BALANS və ya Cash Flow vərəqi tapılmadı.')];
        }

        return $result;
    }

    public function import(string $path, ImportBatch $batch, string $mode = 'replace'): ImportBatch
    {
        $data = $this->parse($path);
        if ($data['year'] === null) {
            throw new RuntimeException(__('Hesabat ili təyin olunmadı (BALANS başlığında və ya Cash Flow aylarında tarix yoxdur).'));
        }
        $year = $data['year'];
        DB::transaction(function () use ($data, $batch, $year) {
            // eyni ilin əvvəlki məlumatı əvəzlənir
            $previous = [];
            foreach (self::TABLES as $table) {
                foreach (DB::table($table)->where('year', $year)->distinct()->pluck('import_batch_id') as $id) {
                    $previous[$id] = true;
                }
                DB::table($table)->where('year', $year)->delete();
            }
            ImportBatch::query()->whereIn('id', array_keys($previous))->where('id', '!=', $batch->id)
                ->update(['status' => 'replaced', 'rolled_back_at' => now()]);

            $now = now();
            $stamp = fn (array $rows) => array_map(fn ($r) => $r + ['year' => $year, 'import_batch_id' => $batch->id, 'created_at' => $now, 'updated_at' => $now], $rows);
            foreach (array_chunk($stamp(array_map(fn ($r) => $r + ['as_of' => $data['as_of']], $data['balance'])), 500) as $chunk) {
                DB::table('acc_balance_lines')->insert($chunk);
            }
            foreach (array_chunk($stamp($data['pl']), 500) as $chunk) {
                DB::table('acc_pl_lines')->insert($chunk);
            }
            foreach (array_chunk($stamp($data['flows']), 500) as $chunk) {
                DB::table('bank_flow_lines')->insert($chunk);
            }
            foreach (array_chunk($stamp($data['flow_balances']), 500) as $chunk) {
                DB::table('bank_flow_balances')->insert($chunk);
            }
            foreach (array_chunk($stamp($data['ledger']), 500) as $chunk) {
                DB::table('acc_ledger_lines')->insert($chunk);
            }

            $batch->fill([
                'status' => 'done',
                'rows_total' => count($data['balance']) + count($data['pl']) + count($data['flows']) + count($data['ledger']),
                'rows_pending' => 0,
                'rows_error' => count($data['errors']),
                'sheets' => ['year' => $year, 'BALANS' => count($data['balance']), 'MZ' => count($data['pl']), 'Cash Flow' => count($data['flows']), 'ledger' => count($data['ledger'])],
                'errors' => array_map(fn ($e) => ['sheet' => $e[0], 'row' => $e[1], 'message' => $e[2]], $data['errors']),
                'imported_at' => now(),
            ])->save();
        });

        return $batch->refresh();
    }

    /**
     * @return array{year: int|null, as_of: string|null, balance: list<array<string, mixed>>, pl: list<array<string, mixed>>, flows: list<array<string, mixed>>, flow_balances: list<array<string, mixed>>, ledger: list<array<string, mixed>>, errors: list<array{0: string, 1: int, 2: string}>}
     */
    public function parse(string $path): array
    {
        if ($this->parsed !== null && $this->parsedPath === $path) {
            return $this->parsed;
        }
        @ini_set('memory_limit', '512M');
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $out = ['year' => null, 'as_of' => null, 'balance' => [], 'pl' => [], 'flows' => [], 'flow_balances' => [], 'ledger' => [], 'errors' => []];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $name = trim($sheet->getTitle());
            $rows = $this->rows($sheet);
            $key = NameNormalizer::normalize($name);
            if ($key === 'balans') {
                $this->parseBalance($rows, $out);
            } elseif ($key === 'mz') {
                $this->parsePl($rows, $out);
            } elseif ($key === 'cash flow') {
                $this->parseCashFlow($rows, $out);
            } elseif (preg_match('/^\d{2,3}$/', $name)) {
                $this->parseLedger($name, $rows, $out);
            }
        }
        if ($out['year'] === null && $out['flows'] !== []) {
            $out['year'] = (int) substr($out['flows'][0]['period'], 0, 4);
        }
        $out['as_of'] ??= $out['year'] ? $out['year'].'-12-31' : null;
        $book->disconnectWorksheets();

        $this->parsedPath = $path;

        return $this->parsed = $out;
    }

    /**
     * @return array<int, array<int, mixed>> Excel sətir nömrəsi => sütun indeksi => dəyər
     */
    private function rows(Worksheet $sheet): array
    {
        $result = [];
        foreach ($sheet->getRowIterator() as $row) {
            $values = [];
            foreach ($row->getCellIterator() as $cell) {
                try {
                    $value = $cell->isFormula() ? ($cell->getOldCalculatedValue() ?? $cell->getCalculatedValue()) : $cell->getValue();
                } catch (Throwable) {
                    $value = $cell->getOldCalculatedValue();
                }
                if (is_string($value)) {
                    $value = rtrim($value);
                }
                if ($value !== null && $value !== '') {
                    $values[Coordinate::columnIndexFromString($cell->getColumn()) - 1] = $value;
                }
            }
            if ($values !== []) {
                $result[$row->getRowIndex()] = $values;
            }
        }

        return $result;
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $clean = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim($value));

        return is_numeric($clean) ? (float) $clean : null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (is_int($value) || is_float($value)) {
            return $value > 30000 && $value < 80000 ? Carbon::instance(ExcelDate::excelToDateTimeObject($value)) : null;
        }
        if (is_string($value) && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($value), $m)) {
            return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, mixed>  $out
     */
    private function parseBalance(array $rows, array &$out): void
    {
        $side = null;
        foreach ($rows as $no => $r) {
            $a = isset($r[0]) ? trim((string) $r[0]) : '';
            if (str_starts_with(NameNormalizer::normalize($a), 'hesab')) {
                $side = $side === null ? 'asset' : 'liability';
                if (($date = self::date($r[2] ?? null)) !== null) {
                    $out['as_of'] = $date->toDateString();
                    $out['year'] = $date->year;
                }

                continue;
            }
            if ($side === null || ! isset($r[1]) || ($code = self::number($r[0] ?? null)) === null) {
                continue;
            }
            $out['balance'][] = [
                'side' => $side,
                'legacy_code' => (string) (int) $code,
                'name' => trim((string) $r[1]),
                'amount' => self::number($r[2] ?? null) ?? 0.0,
                'row_no' => $no,
            ];
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, mixed>  $out
     */
    private function parsePl(array $rows, array &$out): void
    {
        $section = null;
        foreach ($rows as $no => $r) {
            $label = NameNormalizer::normalize((string) ($r[1] ?? ''));
            if ($label === 'gelirler') {
                $section = 'income';

                continue;
            }
            if ($label === 'xercler') {
                $section = 'expense';

                continue;
            }
            if (str_contains($label, 'menfeet zerer') || str_contains($label, 'menfeet (zerer)')) {
                $out['pl'][] = ['section' => 'result', 'tax_code' => null, 'name' => trim((string) $r[1]), 'amount' => self::number($r[2] ?? null) ?? 0.0, 'row_no' => $no];

                continue;
            }
            if ($section === null || ! isset($r[0]) || ! isset($r[1]) || $label === 'cemi') {
                continue;
            }
            $out['pl'][] = [
                'section' => $section,
                'tax_code' => trim((string) $r[0]),
                'name' => trim((string) $r[1]),
                'amount' => self::number($r[2] ?? null) ?? 0.0,
                'row_no' => $no,
            ];
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, mixed>  $out
     */
    private function parseCashFlow(array $rows, array &$out): void
    {
        $months = [];
        $section = null;
        $balances = false;
        foreach ($rows as $no => $r) {
            if ($months === []) {
                foreach ($r as $col => $value) {
                    if ($col > 0 && ($date = self::date($value)) !== null) {
                        $months[$col] = $date->format('Y-m');
                    }
                }
                if ($months !== []) {
                    $out['year'] ??= (int) substr(reset($months), 0, 4);
                }

                continue;
            }
            $label = trim((string) ($r[0] ?? ''));
            $key = NameNormalizer::normalize($label);
            if ($label === '') {
                continue;
            }
            if (str_starts_with($key, 'dovrun evveline')) {
                $this->flowRow($r, $months, 'opening', $label, $no, $out);

                continue;
            }
            if (str_starts_with($key, 'dovrun sonuna')) {
                $this->flowRow($r, $months, 'closing', $label, $no, $out);

                continue;
            }
            if ($key === 'gelirler') {
                $section = 'income';

                continue;
            }
            if ($key === 'odenisler') {
                $section = 'payment';

                continue;
            }
            if (str_starts_with($key, 'diger emeliyyat')) {
                $section = 'other';

                continue;
            }
            if (str_contains($key, 'aciqlamasi')) {
                $balances = true;

                continue;
            }
            if (str_starts_with($key, 'total')) {
                continue;
            }
            if ($balances) {
                foreach ($months as $col => $period) {
                    if (($amount = self::number($r[$col] ?? null)) !== null) {
                        $out['flow_balances'][] = ['period' => $period, 'account' => $label, 'amount' => $amount];
                    }
                }

                continue;
            }
            if ($section !== null) {
                $this->flowRow($r, $months, $section, $label, $no, $out);
            }
        }
        if ($months === []) {
            $out['errors'][] = ['Cash Flow', 1, __('Cash Flow vərəqində ay başlıqları (tarix) tapılmadı.')];
        }
    }

    /**
     * @param  array<int, mixed>  $r
     * @param  array<int, string>  $months
     * @param  array<string, mixed>  $out
     */
    private function flowRow(array $r, array $months, string $section, string $label, int $no, array &$out): void
    {
        foreach ($months as $col => $period) {
            $amount = self::number($r[$col] ?? null);
            if ($amount === null || abs($amount) < 0.005) {
                continue;
            }
            $out['flows'][] = [
                'period' => $period,
                'section' => $section,
                'counterparty' => $label,
                'normalized' => NameNormalizer::normalize($label),
                'amount' => $amount,
                'row_no' => $no,
            ];
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, mixed>  $out
     */
    private function parseLedger(string $code, array $rows, array &$out): void
    {
        $counterparty = null;
        foreach ($rows as $no => $r) {
            $debit = self::number($r[1] ?? null);
            $credit = self::number($r[2] ?? null);
            $raw = (string) ($r[0] ?? '');
            $label = trim($raw);
            if (($debit === null && $credit === null) || $label === '') {
                continue;
            }
            $lower = mb_strtolower($label);
            if (str_starts_with($lower, 'йекун') || str_starts_with($lower, 'yekun') || str_starts_with($lower, 'итог')) {
                continue;
            }
            $isDocument = $raw !== ltrim($raw);
            if (! $isDocument) {
                $counterparty = $label;
            }
            $out['ledger'][] = [
                'legacy_code' => $code,
                'counterparty' => $counterparty ?? $label,
                'document' => $isDocument ? $label : null,
                'debit' => $debit ?? 0.0,
                'credit' => $credit ?? 0.0,
                'row_no' => $no,
            ];
        }
    }
}
