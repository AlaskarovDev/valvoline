@props(['label', 'value', 'delta' => null, 'meta' => null, 'href' => null, 'tone' => null, 'inverse' => false, 'big' => false])
@php
    $toneClass = match ($tone) { 'pos' => 'pos', 'neg' => 'neg', 'warn' => 'warn', default => '' };
    $deltaGood = $delta !== null && ($inverse ? $delta < 0 : $delta > 0);
@endphp
<div {{ $attributes->merge(['class' => 'card kpi']) }}>
    <div class="kpi-label">{{ $label }}</div>
    <div class="kpi-value {{ $toneClass }} {{ $big ? 'text-3xl' : '' }}">
        @if ($href)<a href="{{ $href }}" class="hover:underline">{{ $value }}</a>@else{{ $value }}@endif
    </div>
    @if ($delta !== null || $meta)
        <div class="kpi-meta flex flex-wrap items-center gap-x-2">
            @if ($delta !== null)
                <span class="{{ $deltaGood ? 'pos' : 'neg' }} font-semibold">{{ $delta >= 0 ? '▲' : '▼' }} {{ \App\Support\Fmt::pct(abs($delta)) }}</span>
            @endif
            @if ($meta)<span>{{ $meta }}</span>@endif
        </div>
    @endif
</div>
