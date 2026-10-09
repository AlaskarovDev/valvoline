<?php

namespace App\Imports;

use App\Models\ImportBatch;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hər Excel fayl tipi üçün baza import sinfi.
 *
 * Axın: preview() faylı tam oxuyur (yazmadan), ilk 50 sətri və xətaları qaytarır →
 * import() sətirləri `import_batches` qeydinə bağlı yazır → rollback() həmin batch-i tam silir.
 * Tanınmayan Mal_Kodu / Musteri_Kodu sətri rədd etmir — `status = pending` ilə saxlanılır.
 */
abstract class Importer
{
    public const PREVIEW_ROWS = 50;

    public const MAX_ERRORS = 500;

    protected ImportContext $context;

    /** Təbii açarla dublikat olduğu üçün yazılmayan sətirlər (append rejimi). */
    protected int $skipped = 0;

    abstract public function type(): string;

    abstract public function title(): string;

    abstract public function fileHint(): string;

    /**
     * Import ediləcək vərəqlər və onların məcburi sütunları (ardıcıllıqla emal olunur).
     *
     * @return array<string, list<string>>
     */
    abstract public function sheets(): array;

    /**
     * Excel sətrini DB sətrinə çevirir. Yazma etmir.
     *
     * @param  array<string, mixed>  $row
     * @return array{data: array<string, mixed>|null, errors: list<string>, pending: list<string>}
     */
    abstract protected function mapRow(string $sheet, array $row, int $rowNo): array;

    /**
     * Xəritələnmiş sətirləri yazır.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    abstract protected function persist(string $sheet, array $rows, ImportBatch $batch): void;

    /**
     * Master məlumat (mallar, müştərilər) upsert olunur; əməliyyat faylları əvəzlənir.
     */
    public function isMasterData(): bool
    {
        return false;
    }

    /**
     * Bu tipin hər import partiyasının yazdığı cədvəllər (rollback / əvəzləmə üçün).
     *
     * @return list<string>
     */
    abstract public function tables(): array;

    public function context(): ImportContext
    {
        return $this->context ??= new ImportContext;
    }

    /**
     * @return array{sheets: array<string, array{headers: list<string>, missing: list<string>, rows: int, sample: list<array<string, mixed>>, pending: int, errors: int}>, errors: list<array{sheet: string, row: int, message: string}>, pending: array<string, int>, ok: bool}
     */
    public function preview(string $path): array
    {
        $inspection = Spreadsheet::inspect($path);
        $result = ['sheets' => [], 'errors' => [], 'pending' => [], 'ok' => true];

        foreach ($this->sheets() as $sheet => $required) {
            $found = $this->findSheet($inspection, $sheet);
            if ($found === null) {
                $result['ok'] = false;
                $result['errors'][] = ['sheet' => $sheet, 'row' => 0, 'message' => "«{$sheet}» vərəqi tapılmadı. Fayldakı vərəqlər: ".implode(', ', array_keys($inspection))];

                continue;
            }
            $headers = $inspection[$found]['headers'];
            $missing = array_values(array_diff($required, $headers));
            $info = ['headers' => $headers, 'missing' => $missing, 'rows' => 0, 'sample' => [], 'pending' => 0, 'errors' => 0];
            if ($missing !== []) {
                $result['ok'] = false;
                $result['errors'][] = ['sheet' => $sheet, 'row' => 1, 'message' => 'Çatışmayan sütunlar: '.implode(', ', $missing)];
                $result['sheets'][$sheet] = $info;

                continue;
            }
            foreach (Spreadsheet::rows($path, $found) as $rowNo => $row) {
                $info['rows']++;
                $mapped = $this->mapRow($sheet, $row, $rowNo);
                if (count($info['sample']) < self::PREVIEW_ROWS) {
                    $info['sample'][] = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('d.m.Y') : $v, $row)
                        + ['_status' => $mapped['errors'] !== [] ? 'error' : ($mapped['pending'] !== [] ? 'pending' : 'ok')];
                }
                foreach ($mapped['errors'] as $message) {
                    $info['errors']++;
                    if (count($result['errors']) < self::MAX_ERRORS) {
                        $result['errors'][] = ['sheet' => $sheet, 'row' => $rowNo, 'message' => $message];
                    }
                }
                if ($mapped['pending'] !== []) {
                    $info['pending']++;
                    foreach ($mapped['pending'] as $reason) {
                        $result['pending'][$reason] = ($result['pending'][$reason] ?? 0) + 1;
                    }
                }
            }
            $result['sheets'][$sheet] = $info;
        }
        arsort($result['pending']);

        return $result;
    }

    /**
     * @param  'replace'|'append'  $mode
     */
    public function import(string $path, ImportBatch $batch, string $mode = 'replace'): ImportBatch
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        $inspection = Spreadsheet::inspect($path);
        foreach ($this->sheets() as $sheet => $required) {
            $found = $this->findSheet($inspection, $sheet);
            if ($found === null) {
                throw new RuntimeException("«{$sheet}» vərəqi tapılmadı.");
            }
            $missing = array_diff($required, $inspection[$found]['headers']);
            if ($missing !== []) {
                throw new RuntimeException("«{$sheet}»: çatışmayan sütunlar — ".implode(', ', $missing));
            }
        }

        $this->skipped = 0;
        DB::transaction(function () use ($path, $batch, $mode, $inspection) {
            if ($mode === 'replace' && ! $this->isMasterData()) {
                $this->replacePrevious($batch);
            }
            $this->context()->reload();
            $total = 0;
            $pending = 0;
            $errors = [];
            $sheetCounts = [];
            foreach (array_keys($this->sheets()) as $sheet) {
                $found = $this->findSheet($inspection, $sheet);
                $rows = [];
                foreach (Spreadsheet::rows($path, $found) as $rowNo => $row) {
                    $mapped = $this->mapRow($sheet, $row, $rowNo);
                    foreach ($mapped['errors'] as $message) {
                        if (count($errors) < self::MAX_ERRORS) {
                            $errors[] = ['sheet' => $sheet, 'row' => $rowNo, 'message' => $message];
                        }
                    }
                    if ($mapped['data'] === null) {
                        continue;
                    }
                    if ($mapped['pending'] !== []) {
                        $pending++;
                    }
                    $rows[] = $mapped['data'] + ['row_no' => $rowNo];
                }
                $skippedBefore = $this->skipped;
                $this->persist($sheet, $rows, $batch);
                $written = count($rows) - ($this->skipped - $skippedBefore);
                $sheetCounts[$sheet] = $written;
                $total += $written;
                $this->context()->reload();
            }
            $batch->fill([
                'status' => 'done',
                'rows_total' => $total,
                'rows_pending' => $pending,
                'rows_error' => count($errors),
                'sheets' => $sheetCounts + ($this->skipped > 0 ? ['Dublikat (ötürüldü)' => $this->skipped] : []),
                'errors' => $errors,
                'imported_at' => now(),
            ])->save();
        });

        return $batch->refresh();
    }

    public function rollback(ImportBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            foreach (array_reverse($this->tables()) as $table) {
                DB::table($table)->where('import_batch_id', $batch->id)->delete();
            }
            $batch->fill(['status' => 'rolled_back', 'rolled_back_at' => now()])->save();
        });
    }

    protected function replacePrevious(ImportBatch $current): void
    {
        $previous = ImportBatch::query()
            ->where('file_type', $this->type())
            ->where('status', 'done')
            ->where('id', '!=', $current->id)
            ->get();
        foreach ($previous as $old) {
            foreach (array_reverse($this->tables()) as $table) {
                DB::table($table)->where('import_batch_id', $old->id)->delete();
            }
            $old->fill(['status' => 'replaced', 'rolled_back_at' => now()])->save();
        }
        // əvvəlki partiyaya bağlı olmayan köhnə sətirlər (məs. əl ilə yazılmış test sətirləri) toxunulmaz qalır
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function insertChunked(string $table, array $rows, int $size = 500): void
    {
        $now = now();
        foreach (array_chunk($rows, $size) as $chunk) {
            DB::table($table)->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    protected function findSheet(array $inspection, string $sheet): ?string
    {
        foreach (array_keys($inspection) as $name) {
            if (mb_strtolower(trim((string) $name)) === mb_strtolower($sheet)) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * @return array{data: array<string, mixed>|null, errors: list<string>, pending: list<string>}
     */
    protected static function result(?array $data, array $errors = [], array $pending = []): array
    {
        return ['data' => $data, 'errors' => $errors, 'pending' => $pending];
    }

    protected static function period(Carbon $date): string
    {
        return $date->format('Y-m');
    }

    /**
     * "Bəli" / "Xeyr" / true / 1.
     */
    protected static function yes(mixed $value): bool
    {
        $text = mb_strtolower(trim((string) Spreadsheet::text($value)));

        return in_array($text, ['bəli', 'beli', 'hə', 'he', 'yes', 'true', '1', 'b'], true);
    }
}
