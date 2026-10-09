<?php

namespace App\Console\Commands;

use App\Imports\ImporterRegistry;
use App\Models\ImportBatch;
use App\Services\AnalyticsRebuilder;
use Illuminate\Console\Command;

class AnalyticsImport extends Command
{
    protected $signature = 'analytics:import
        {dir? : Faylların olduğu qovluq (standart: storage/import)}
        {--only= : Yalnız bu tip(lər), vergüllə: products,customers,purchases,sales,returns,payments,stock,cash}
        {--mode=replace : replace (köhnə partiyanı sil) və ya append (dublikatları ötür)}
        {--no-rebuild : Sonda analytics:rebuild işlətmə}';

    protected $description = '01–08 Excel fayllarını düzgün ardıcıllıqla import edir və hesablamaları yenidən qurur';

    public function handle(AnalyticsRebuilder $rebuilder): int
    {
        $dir = rtrim($this->argument('dir') ?? storage_path('import'), '/\\');
        $only = array_filter(explode(',', (string) $this->option('only')));
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
        $imported = 0;

        foreach (ImporterRegistry::all() as $type => $importer) {
            if ($only !== [] && ! in_array($type, $only, true)) {
                continue;
            }
            $match = null;
            foreach ($files as $file) {
                if (str_starts_with(basename($file), '~$')) {
                    continue;
                }
                if (ImporterRegistry::guess($file) === $type) {
                    $match = $file;
                }
            }
            if ($match === null) {
                $this->warn("  {$importer->fileHint()} tapılmadı — ötürülür");

                continue;
            }
            $started = microtime(true);
            $batch = ImportBatch::query()->create(['file_type' => $type, 'file_name' => basename($match), 'status' => 'preview']);
            $batch = $importer->import($match, $batch, (string) $this->option('mode'));
            $imported++;
            $this->line(sprintf('  <info>✓</info> %-24s %6d sətir, %4d gözləmədə, %3d xəta  (%.1f san)',
                basename($match), $batch->rows_total, $batch->rows_pending, $batch->rows_error, microtime(true) - $started));
            foreach (array_slice($batch->errors ?? [], 0, 5) as $error) {
                $this->line("      <comment>{$error['sheet']} #{$error['row']}: {$error['message']}</comment>");
            }
        }

        if ($imported > 0 && ! $this->option('no-rebuild')) {
            $this->newLine();
            $this->call('analytics:rebuild');
        }

        return self::SUCCESS;
    }
}
