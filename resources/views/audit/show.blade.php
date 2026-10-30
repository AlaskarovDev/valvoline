@php
    $payload = $log->payload ?? [];
    $changes = $payload['changes'] ?? [];
    $show = fn ($v) => is_scalar($v) || $v === null ? (string) ($v ?? '—') : json_encode($v, JSON_UNESCAPED_UNICODE);
@endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('audit.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ $log->created_at?->format('d.m.Y H:i:s') }}</span>
        <span class="badge badge-primary">{{ $log->user_name ?? '—' }}</span>
        <span @class(['badge', 'badge-neg' => $log->outcome !== 'ok'])>{{ $log->outcome }}</span>
        @if ($url)<a href="{{ $url }}" class="badge badge-primary">{{ __('Obyekti aç') }}</a>@endif
    </div>
    <section class="card p-4">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-[180px_1fr]">
            <dt class="text-muted">{{ __('Əməliyyat') }}</dt><dd>{{ $log->label() }} <span class="font-mono text-xs text-muted">({{ $log->method }} {{ $log->action }})</span></dd>
            <dt class="text-muted">{{ __('Sənəd / obyekt') }}</dt><dd>{{ trim(str_replace('|', ' ', (string) $log->doc_nos)) ?: ($log->subject_type ? $log->subject_type.' #'.$log->subject_id : '—') }}</dd>
            <dt class="text-muted">{{ __('Nəticə') }}</dt><dd>{{ $log->summary ?? '—' }}</dd>
            <dt class="text-muted">IP</dt><dd class="font-mono text-xs">{{ $log->ip }}</dd>
            <dt class="text-muted">{{ __('Brauzer') }}</dt><dd class="text-xs text-muted">{{ $log->user_agent }}</dd>
        </dl>
    </section>
    @if ($changes)
        <section class="card overflow-hidden">
            <div class="card-head"><h2 class="card-title">{{ __('Dəyişən sahələr') }}</h2></div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>{{ __('Sahə') }}</th><th>{{ __('Əvvəl') }}</th><th>{{ __('Sonra') }}</th></tr></thead>
                    <tbody>
                        @foreach ($changes as $field => $c)
                            <tr><td class="font-mono text-xs">{{ $field }}</td><td class="wrap neg">{{ $show($c['from'] ?? null) }}</td><td class="wrap pos">{{ $show($c['to'] ?? null) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
    @foreach (['input' => __('Göndərilən məlumat'), 'before' => __('Əməliyyatdan əvvəlki vəziyyət')] as $key => $heading)
        @if (! empty($payload[$key]))
            <section class="card overflow-hidden">
                <div class="card-head"><h2 class="card-title">{{ $heading }}</h2></div>
                <pre class="overflow-x-auto p-4 text-xs leading-relaxed">{{ json_encode($payload[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </section>
        @endif
    @endforeach
</x-layouts.app>
