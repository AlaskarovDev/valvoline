<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Bazanın ehtiyat nüsxəsini storage/app/backups-a yazır (son 14 nüsxə saxlanılır)';

    public function handle(BackupService $backups): int
    {
        $result = $backups->create();
        $this->info($result['name'].' — '.$result['tables'].' cədvəl, '.$result['rows'].' sətir, '.round($result['size'] / 1024).' KB');

        return self::SUCCESS;
    }
}
