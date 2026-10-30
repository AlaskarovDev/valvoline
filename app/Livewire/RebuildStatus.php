<?php

namespace App\Livewire;

use App\Jobs\RebuildAnalytics;
use App\Services\AnalyticsRebuilder;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * analytics:rebuild irəliləyişi (Cache-dən) və «Yenidən hesabla» düyməsi.
 */
class RebuildStatus extends Component
{
    public function rebuild(): void
    {
        abort_unless(auth()->user()?->can('data'), 403);
        $status = AnalyticsRebuilder::status();
        if (in_array($status['status'] ?? null, ['running', 'queued'], true)) {
            return;
        }
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }

    public function render(): View
    {
        $status = AnalyticsRebuilder::status();

        return view('livewire.rebuild-status', [
            'status' => $status,
            'busy' => in_array($status['status'] ?? null, ['running', 'queued'], true),
        ]);
    }
}
