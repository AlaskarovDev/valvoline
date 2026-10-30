<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Bazanın ehtiyat nüsxəsi: bütün cədvəllər SQL (CREATE + INSERT) kimi gzip faylına yazılır
 * (storage/app/backups — vebdən bağlıdır). mysqldump tələb olunmur; son KEEP nüsxə saxlanılır.
 */
class BackupService
{
    public const KEEP = 14;

    private const PATTERN = '/^valvo-\d{8}-\d{6}\.sql\.gz$/';

    public function directory(): string
    {
        return storage_path('app/backups');
    }

    /**
     * @return list<array{name: string, size: int, at: Carbon}>
     */
    public function list(): array
    {
        if (! is_dir($this->directory())) {
            return [];
        }
        $out = [];
        foreach (File::files($this->directory()) as $file) {
            if (preg_match(self::PATTERN, $file->getFilename())) {
                $out[] = ['name' => $file->getFilename(), 'size' => $file->getSize(), 'at' => Carbon::createFromTimestamp($file->getMTime())];
            }
        }
        usort($out, fn ($a, $b) => $b['name'] <=> $a['name']);

        return $out;
    }

    public function path(string $name): ?string
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        return preg_match(self::PATTERN, $name) && is_file($path) ? $path : null;
    }

    public function isDue(int $hours = 24): bool
    {
        $last = $this->list()[0]['at'] ?? null;

        return $last === null || $last->lt(now()->subHours($hours));
    }

    /**
     * @return array{name: string, size: int, tables: int, rows: int}
     */
    public function create(): array
    {
        File::ensureDirectoryExists($this->directory());
        $name = 'valvo-'.now()->format('Ymd-His').'.sql.gz';
        $tmp = $this->directory().DIRECTORY_SEPARATOR.$name.'.part';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new RuntimeException(__('Ehtiyat nüsxəsi faylı yaradıla bilmədi.'));
        }
        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();
        $tables = $this->tables($driver);
        $rows = 0;
        gzwrite($gz, '-- Valvo Analitika ehtiyat nüsxəsi '.now()->toDateTimeString().' ('.$driver.")\n");
        if ($driver === 'mysql' || $driver === 'mariadb') {
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        }
        foreach ($tables as $table) {
            $quoted = $driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`';
            gzwrite($gz, "\nDROP TABLE IF EXISTS {$quoted};\n".$this->createStatement($driver, $table).";\n");
            $batch = [];
            foreach (DB::table($table)->cursor() as $row) {
                $batch[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), (array) $row)).')';
                $rows++;
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO {$quoted} VALUES ".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO {$quoted} VALUES ".implode(",\n", $batch).";\n");
            }
        }
        if ($driver === 'mysql' || $driver === 'mariadb') {
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        }
        gzclose($gz);
        $final = $this->directory().DIRECTORY_SEPARATOR.$name;
        rename($tmp, $final);
        $this->prune();

        return ['name' => $name, 'size' => (int) filesize($final), 'tables' => count($tables), 'rows' => $rows];
    }

    public function prune(int $keep = self::KEEP): void
    {
        foreach (array_slice($this->list(), $keep) as $old) {
            @unlink($this->directory().DIRECTORY_SEPARATOR.$old['name']);
        }
    }

    /**
     * @return list<string>
     */
    private function tables(string $driver): array
    {
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')->orderBy('name')->pluck('name')->all();
        }

        return collect(DB::select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']))->map(fn ($r) => array_values((array) $r)[0])->sort()->values()->all();
    }

    private function createStatement(string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            return (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        }

        return (string) array_values((array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`'))[1];
    }
}
