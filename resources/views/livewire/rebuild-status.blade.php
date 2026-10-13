@php use App\Support\Fmt; @endphp
<section class="card" @if($busy) wire:poll.1500ms @endif>
    <div class="card-head">
        <div>
            <h2 class="card-title">Hesablamalar (analytics:rebuild)</h2>
            <div class="card-sub">{{ __('Maya, anbar jurnalı, sifariş cəmləri, aylıq aqreqatlar və müştəri balansları sıfırdan qurulur.') }}</div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" wire:click="rebuild" @disabled($busy)>
            <x-icon name="refresh" size="14" @class(['animate-spin' => $busy])/> {{ $busy ? __('Hesablanır…') : __('Yenidən hesabla') }}
        </button>
    </div>
    <div class="space-y-2 p-4 text-sm" role="status" aria-live="polite">
        @if ($status)
            <div class="flex items-center justify-between gap-3">
                <span @class(['min-w-0 break-words', 'neg' => $status['status'] === 'failed', 'pos' => $status['status'] === 'done'])>{{ __($status['step']) }}</span>
                <span class="num text-muted">{{ $status['pct'] ?? 0 }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-surface-2">
                <div class="h-full rounded-full transition-all" style="width: {{ $status['pct'] ?? 0 }}%; background: var({{ $status['status'] === 'failed' ? '--negative' : '--primary' }})"></div>
            </div>
            @if (($status['status'] ?? null) === 'done' && isset($status['result']))
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    <span>{{ Fmt::date($status['finished_at']) }} {{ \Illuminate\Support\Carbon::parse($status['finished_at'])->format('H:i') }}</span>
                    <span>{{ __(':n anbar hərəkəti', ['n' => Fmt::number($status['result']['movements'])]) }}</span>
                    <span>{{ __(':n gözləmədə', ['n' => Fmt::number(array_sum($status['result']['pending'] ?? []))]) }}</span>
                    <span>{{ __(':n mənfi qalıqlı mal', ['n' => $status['result']['negative_products']]) }}</span>
                    <span>{{ __(':n san', ['n' => $status['result']['seconds']]) }}</span>
                </div>
            @endif
        @else
            <span class="text-muted">{{ __('Hələ hesablanmayıb.') }}</span>
        @endif
    </div>
</section>
