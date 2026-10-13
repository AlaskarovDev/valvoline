@props(['title' => null, 'sub' => null, 'spec', 'height' => 300])
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title)
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $title }}</h2>
                @if ($sub)<div class="card-sub">{{ $sub }}</div>@endif
            </div>
            {{ $actions ?? '' }}
        </div>
    @endif
    <div class="px-2 pb-2 pt-1">
        @if (empty($spec['series']) || (isset($spec['series'][0]['data']) && collect($spec['series'])->every(fn ($s) => empty($s['data']))))
            <div class="grid place-items-center text-sm text-muted" style="height: {{ $height }}px">{{ __('Məlumat yoxdur') }}</div>
        @else
            <div data-chart='@json(array_merge(['height' => $height], $spec))' style="min-height: {{ $height }}px"></div>
        @endif
    </div>
</section>
