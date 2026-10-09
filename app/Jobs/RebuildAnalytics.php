<?php

namespace App\Jobs;

use App\Services\AnalyticsRebuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebuildAnalytics implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(AnalyticsRebuilder $rebuilder): void
    {
        $rebuilder->run();
    }
}
