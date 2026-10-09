<?php

namespace App\Console\Commands;

use App\Support\DemoWorkbooks;
use Illuminate\Console\Command;

class AnalyticsDemoFiles extends Command
{
    protected $signature = 'analytics:demo-files {dir? : Hədəf qovluq (standart: storage/import/demo)} {--orders=3900 : Sifariş sayı}';

    protected $description = 'DEMO: bazadakı mallarla 02–08 Excel fayllarını sintetik məlumatla yaradır (yalnız sınaq üçün)';

    public function handle(): int
    {
        $dir = $this->argument('dir') ?? storage_path('import/demo');
        $files = (new DemoWorkbooks)->generate($dir, (int) $this->option('orders'));
        foreach ($files as $file) {
            $this->line('  <info>✓</info> '.$file);
        }
        $this->warn('Bu fayllar sintetikdir — real rəqəmlər deyil.');

        return self::SUCCESS;
    }
}
