<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite bazasındakı bütün məlumatı cari (MySQL) bazaya köçürür: hədəf cədvəllər təmizlənir,
 * sətirlər id-ləri ilə birlikdə hissə-hissə yazılır, sonda hər cədvəlin sətir sayı tutuşdurulur.
 * Mənbə fayla toxunulmur.
 */
class CopyFromSqlite extends Command
{
    protected $signature = 'db:copy-sqlite {source? : SQLite faylı (standart: storage/database.sqlite)} {--force : Təsdiq soruşma}';

    protected $description = 'SQLite bazasındakı məlumatı cari MySQL bazasına köçürür (sətir sayları yoxlanılır)';

    /** Köçürülməyən texniki cədvəllər. */
    private const SKIP = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    public function handle(): int
    {
        $source = $this->argument('source') ?? storage_path('database.sqlite');
        if (! is_file($source)) {
            $this->error("SQLite faylı tapılmadı: {$source}");

            return self::FAILURE;
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->error('Hədəf baza da SQLite-dır — əvvəlcə .env-də DB_CONNECTION=mysql təyin edin.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Hədəf bazadakı cədvəllər təmizlənib SQLite məlumatı ilə doldurulacaq. Davam?')) {
            return self::FAILURE;
        }

        config(['database.connections.sqlite_source' => [
            'driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        $from = DB::connection('sqlite_source');
        $to = DB::connection();
        $sourceTables = collect($from->select("SELECT name FROM sqlite_master WHERE type = 'table'"))->pluck('name')->all();
        $database = $to->getDatabaseName();
        $targetTables = collect(Schema::getTables())
            ->filter(fn ($t) => ($t['schema'] ?? $database) === $database)
            ->pluck('name')->unique()->values()->all();

        @set_time_limit(0);
        Schema::disableForeignKeyConstraints();
        $report = [];
        $ok = true;
        try {
            foreach ($targetTables as $table) {
                if (in_array($table, self::SKIP, true) || ! in_array($table, $sourceTables, true)) {
                    continue;
                }
                $columns = array_values(array_intersect(
                    Schema::getColumnListing($table),
                    array_column($from->select("PRAGMA table_info(\"{$table}\")"), 'name'),
                ));
                $to->table($table)->delete();
                $query = $from->table($table)->select($columns);
                if (in_array('id', $columns, true)) {
                    $query->orderBy('id');
                }
                $copied = 0;
                foreach ($query->cursor()->chunk(500) as $chunk) {
                    $to->table($table)->insert($chunk->map(fn ($row) => (array) $row)->all());
                    $copied += $chunk->count();
                }
                $expected = $from->table($table)->count();
                $actual = $to->table($table)->count();
                $ok = $ok && $expected === $actual;
                $report[] = [$table, $expected, $actual, $expected === $actual ? 'ok' : 'FƏRQ'];
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->table(['Cədvəl', 'SQLite', 'MySQL', ''], $report);
        if (! $ok) {
            $this->error('Sətir sayları uyğun gəlmir.');

            return self::FAILURE;
        }
        $this->info(count($report).' cədvəl köçürüldü, sətir sayları eynidir.');

        return self::SUCCESS;
    }
}
