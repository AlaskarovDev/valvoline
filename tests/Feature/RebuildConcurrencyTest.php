<?php

namespace Tests\Feature;

use App\Services\AnalyticsRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * SQLite eyni anda bir yazıçıya icazə verir: iki hesablama paralel işləməməlidir.
 * Hesablama gedərkən gələn sorğu itmir — işləyən proses bitəndə bir tur da keçir.
 */
class RebuildConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_rebuild_is_coalesced_while_one_is_running(): void
    {
        $held = Cache::lock(AnalyticsRebuilder::LOCK_KEY, 60);
        $this->assertTrue($held->get());

        $this->assertNull(app(AnalyticsRebuilder::class)->runExclusive(), 'kilid tutulubsa ikinci hesablama başlamır');
        $this->assertTrue(Cache::get(AnalyticsRebuilder::AGAIN_KEY), 'sorğu yadda saxlanılır');

        $held->release();
        $this->assertIsArray(app(AnalyticsRebuilder::class)->runExclusive());
        $this->assertNull(Cache::get(AnalyticsRebuilder::AGAIN_KEY), 'yığılmış sorğu icra olunub');
        $this->assertSame('done', AnalyticsRebuilder::status()['status']);
        $this->assertTrue(Cache::lock(AnalyticsRebuilder::LOCK_KEY, 1)->get(), 'kilid azad edilib');
    }

    public function test_request_during_running_rebuild_triggers_one_more_pass(): void
    {
        $single = 0;
        app(AnalyticsRebuilder::class)->runExclusive(function () use (&$single) {
            $single++;
        });

        $passes = 0;
        app(AnalyticsRebuilder::class)->runExclusive(function () use (&$passes) {
            static $requested = false;
            if (! $requested) {
                $requested = true;
                AnalyticsRebuilder::markQueued(); // hesablama gedərkən yeni import gəlir
            }
            $passes++;
        });
        $this->assertNull(Cache::get(AnalyticsRebuilder::AGAIN_KEY));
        $this->assertSame(2 * $single, $passes, 'hesablama gedərkən gələn sorğu üçün düz bir tur da keçib');
    }
}
