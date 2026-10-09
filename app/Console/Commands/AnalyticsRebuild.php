<?php

namespace App\Console\Commands;

use App\Jobs\RebuildAnalytics;
use App\Services\AnalyticsRebuilder;
use Illuminate\Console\Command;

class AnalyticsRebuild extends Command
{
    protected $signature = 'analytics:rebuild {--queue : İşi növbəyə göndər (queue:work tərəfindən icra olunacaq)}';

    protected $description = 'Bütün maya, anbar, sifariş, aylıq aqreqat və müştəri balansı hesablamalarını sıfırdan qurur';

    public function handle(AnalyticsRebuilder $rebuilder): int
    {
        if ($this->option('queue')) {
            AnalyticsRebuilder::markQueued();
            RebuildAnalytics::dispatch();
            $this->info('Növbəyə göndərildi. İrəliləyişi UI-da (Import səhifəsi) izləyin.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(100);
        $bar->setFormat(' %current%% [%bar%] %message%');
        $bar->setMessage('Başlanır');
        $bar->start();
        $result = $rebuilder->runExclusive(function (string $step, float $pct) use ($bar) {
            $bar->setMessage($step);
            $bar->setProgress((int) round($pct * 100));
        });
        if ($result === null) {
            $this->newLine();
            $this->warn('Başqa hesablama artıq işləyir — o bitəndə bir tur da keçəcək.');

            return self::SUCCESS;
        }
        $bar->setMessage('Hazırdır');
        $bar->finish();
        $this->newLine(2);

        $this->table(['Göstərici', 'Dəyər'], [
            ['Anbar hərəkəti', number_format($result['movements'], 0, ',', ' ')],
            ['Maya ehtiyat yolu ilə (qrup / ilk alış)', $result['fallbacks']],
            ['Mənfi qalıqlı mal', $result['negative_products']],
            ['Gözləmədə sətirlər', array_sum($result['pending'])],
            ['Müddət', $result['seconds'].' san'],
        ]);

        return self::SUCCESS;
    }
}
