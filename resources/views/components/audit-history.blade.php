@props(['type', 'id', 'no' => null])
@php
    // sənədin tarixçəsi: həmin obyekt üzərində əməliyyatlar + nömrəsi popupda keçən yaradılma qeydləri
    $logs = \App\Models\AuditLog::query()
        ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', $type)->where('subject_id', $id))
            ->when($no, fn ($w) => $w->orWhere('doc_nos', 'like', '%|'.$no.'|%')))
        ->orderByDesc('id')->limit(50)->get();
@endphp
@if ($logs->isNotEmpty())
    <section class="card overflow-hidden no-print">
        <div class="card-head"><h2 class="card-title">{{ __('Tarixçə') }}</h2>
            @can('settings')<a href="{{ route('audit.index', ['q' => $no, 'preset' => 'all']) }}" class="btn btn-sm">{{ __('Audit jurnalı') }}</a>@endcan
        </div>
        <ul class="divide-y divide-line text-sm">
            @foreach ($logs as $log)
                <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-4 py-2">
                    <span class="text-xs text-muted tabular-nums">{{ $log->created_at?->format('d.m.Y H:i') }}</span>
                    <span class="font-medium">{{ $log->user_name ?? '—' }}</span>
                    <span>{{ $log->label() }}</span>
                    @if ($log->outcome !== 'ok')<span class="badge badge-neg">{{ $log->outcome === 'denied' ? __('icazə yoxdur') : __('alınmadı') }}</span>@endif
                    @if ($log->summary)<span class="w-full text-xs text-muted">{{ \Illuminate\Support\Str::limit($log->summary, 200) }}</span>@endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
