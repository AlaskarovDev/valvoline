@php use App\Support\Fmt; @endphp
<section class="card" @if($busy) wire:poll.1500ms @endif>
    <div class="card-head">
        <div>
            <h2 class="card-title">Hesablamalar (analytics:rebuild)</h2>
            <div class="card-sub">Maya, anbar jurnalı, sifariş cəmləri, aylıq aqreqatlar və müştəri balansları sıfırdan qurulur.</div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" wire:click="rebuild" @disabled($busy)>
            <x-icon name="refresh" size="14" @class(['animate-spin' => $busy])/> {{ $busy ? 'Hesablanır…' : 'Yenidən hesabla' }}
        </button>
    </div>
    <div class="space-y-2 p-4 text-sm" role="status" aria-live="polite">
        @if ($status)
            <div class="flex items-center justify-between gap-3">
                <span @class(['neg' => $status['status'] === 'failed', 'pos' => $status['status'] === 'done'])>{{ $status['step'] }}</span>
                <span class="num text-muted">{{ $status['pct'] ?? 0 }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-surface-2">
                <div class="h-full rounded-full transition-all" style="width: {{ $status['pct'] ?? 0 }}%; background: var({{ $status['status'] === 'failed' ? '--negative' : '--primary' }})"></div>
            </div>
            @if (($status['status'] ?? null) === 'done' && isset($status['result']))
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    <span>{{ Fmt::date($status['finished_at']) }} {{ \Illuminate\Support\Carbon::parse($status['finished_at'])->format('H:i') }}</span>
                    <span>{{ Fmt::number($status['result']['movements']) }} anbar hərəkəti</span>
                    <span>{{ Fmt::number(array_sum($status['result']['pending'] ?? [])) }} gözləmədə</span>
                    <span>{{ $status['result']['negative_products'] }} mənfi qalıqlı mal</span>
                    <span>{{ $status['result']['seconds'] }} san</span>
                </div>
            @endif
        @else
            <span class="text-muted">Hələ hesablanmayıb.</span>
        @endif
    </div>
</section>
