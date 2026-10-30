<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bütün hesablamaları sıfırdan qurur: istinadlar → maya + anbar jurnalı → sifarişlər → aylıq aqreqat → balanslar.
 * İrəliləyiş Cache-də saxlanılır (UI onu sorğulayır).
 */
class AnalyticsRebuilder
{
    public const CACHE_KEY = 'analytics.rebuild';

    public const LOCK_KEY = 'analytics.rebuild.lock';

    public const AGAIN_KEY = 'analytics.rebuild.again';

    public function __construct(
        private ReferenceResolver $resolver,
        private CostingService $costing,
        private SummaryService $summary,
        private CustomerBalanceService $balances,
    ) {}

    /**
     * Eyni anda yalnız bir hesablama işləyir (SQLite bir yazıçıya icazə verir). Hesablama gedərkən
     * gələn sorğular itmir: «yenidən» bayrağı qoyulur və işləyən proses bitəndə bir tur da keçir.
     *
     * @param  callable(string, float): void|null  $onProgress
     * @return array<string, mixed>|null null — başqa proses artıq işləyir, sorğu ona birləşdirildi
     */
    public function runExclusive(?callable $onProgress = null): ?array
    {
        $lock = Cache::lock(self::LOCK_KEY, 3600);
        if (! $lock->get()) {
            Cache::put(self::AGAIN_KEY, true, 3600);

            return null;
        }
        try {
            do {
                Cache::forget(self::AGAIN_KEY);
                $result = $this->run($onProgress);
            } while (Cache::pull(self::AGAIN_KEY));

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  callable(string, float): void|null  $onProgress
     * @return array<string, mixed>
     */
    public function run(?callable $onProgress = null): array
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        $started = microtime(true);
        $report = function (string $step, float $pct) use ($onProgress, $started) {
            Cache::put(self::CACHE_KEY, [
                'status' => 'running', 'step' => $step, 'pct' => round($pct * 100),
                'started_at' => now()->subSeconds((int) (microtime(true) - $started))->toIso8601String(),
            ], 3600);
            if ($onProgress) {
                $onProgress($step, $pct);
            }
        };

        try {
            $report(__('Gözləmədə olan sətirlər bağlanır'), 0.02);
            $pending = $this->resolver->run();

            $stats = DB::transaction(fn () => $this->costing->run(fn (string $step, float $pct) => $report($step, 0.05 + $pct * 0.6)));

            $report(__('Qaytarmalar sifarişlərə bağlanır'), 0.7);
            DB::transaction(fn () => $this->summary->linkReturnsToOrders());

            $report(__('Sifariş cəmləri hesablanır'), 0.75);
            DB::transaction(fn () => $this->summary->aggregateOrders());

            $report(__('Aylıq aqreqat cədvəl doldurulur'), 0.82);
            DB::transaction(fn () => $this->summary->buildMonthlySummary());

            $report(__('Müştəri balansları və borc yaşı'), 0.9);
            DB::transaction(fn () => $this->balances->rebuild());

            $result = $stats + ['pending' => $pending, 'seconds' => round(microtime(true) - $started, 1)];
            AlertService::touch();
            Cache::put(self::CACHE_KEY, ['status' => 'done', 'step' => __('Hazırdır'), 'pct' => 100, 'finished_at' => now()->toIso8601String(), 'result' => $result], 86400 * 30);
            Cache::put('analytics.version', now()->timestamp, 86400 * 365);

            return $result;
        } catch (Throwable $e) {
            Log::error('analytics:rebuild failed', ['exception' => $e]);
            Cache::put(self::CACHE_KEY, ['status' => 'failed', 'step' => __('Xəta: ').$e->getMessage(), 'pct' => 0, 'finished_at' => now()->toIso8601String()], 86400);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function status(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    public static function markQueued(): void
    {
        if ((self::status()['status'] ?? null) === 'running') {
            Cache::put(self::AGAIN_KEY, true, 3600);

            return;
        }
        Cache::put(self::CACHE_KEY, ['status' => 'queued', 'step' => __('Növbədədir'), 'pct' => 0], 3600);
    }
}
