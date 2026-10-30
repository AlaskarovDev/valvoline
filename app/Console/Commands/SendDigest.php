<?php

namespace App\Console\Commands;

use App\Services\DigestService;
use Illuminate\Console\Command;

class SendDigest extends Command
{
    protected $signature = 'digest:send {--force : Saat 08:00-ı gözləmədən göndər}';

    protected $description = 'Gündəlik e-mail xülasəsini abunə olan istifadəçilərə göndərir (gündə bir dəfə)';

    public function handle(DigestService $digests): int
    {
        $result = $digests->sendDue((bool) $this->option('force'));
        $this->info('Göndərildi: '.$result['sent'].', xəta: '.$result['failed']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
