<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// serverdə cron qurulubsa (php artisan schedule:run) — gündəlik ehtiyat nüsxəsi; cron yoxdursa DailyBackup middleware-i edir
Schedule::command('backup:database')->dailyAt('03:00');
