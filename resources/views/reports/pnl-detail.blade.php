@php
    use App\Support\Fmt;
    $fmt = fn ($v) => $type === 'pct' ? Fmt::pct($v) : Fmt::money($v);
@endphp
<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="text-base font-semibold" data-modal-title>{{ $label }}</h2>
        <div class="text-xs text-muted">{{ $period }} · {{ $direction }}</div>
    </div>
    <div class="shrink-0 text-right text-lg font-bold tabular-nums {{ $result < 0 ? 'neg' : '' }}">{{ $fmt($result) }}</div>
</div>

<div class="space-y-4 px-5 py-4">
    <div class="rounded-lg border border-line bg-surface-2 px-4 py-3 text-sm">
        <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Necə hesablanır') }}</div>
        {{ $formula }}
    </div>

    @if ($components)
        <div class="overflow-hidden rounded-lg border border-line">
            <table class="tbl">
                <tbody>
                @foreach ($components as $c)
                    <tr>
                        <td class="w-6 text-center text-muted">{{ $c['sign'] }}</td>
                        <td class="wrap">
                            @if ($childLinks && isset($c['name']))
                                <a href="{{ $childLinks($c['name']) }}" data-modal class="cell-link">{{ $c['label'] }}</a>
                            @else
                                {{ $c['label'] }}
                            @endif
                        </td>
                        {{-- marja düsturunda komponentlər məbləğdir, nəticə faizdir --}}
                        <td class="num {{ $c['value'] < 0 ? 'neg' : '' }}">{{ Fmt::money($c['value']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr><td></td><td>= {{ $label }}</td><td class="num">{{ $fmt($result) }}</td></tr></tfoot>
            </table>
        </div>
    @endif

    @if ($records)
        <section>
            <h3 class="mb-2 text-sm font-semibold">{{ $records['title'] }}</h3>
            <div class="tbl-wrap max-h-[50vh] rounded-lg border border-line">
                <table class="tbl">
                    <thead><tr>
                        @foreach ($records['columns'] as $i => $column)<th @class(['num' => $i > 0])>{{ $column }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @forelse ($records['rows'] as $row)
                        <tr>
                            @foreach ($row as $i => $value)
                                <td @class(['num' => $i > 0, 'wrap' => $i === 0])>{{ is_float($value) ? Fmt::money($value) : $value }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($records['columns']) }}" class="py-6 text-center text-muted">{{ __('Məlumat yoxdur') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($records['note'])<div class="mt-1 text-xs text-muted">{{ $records['note'] }}</div>@endif
        </section>
    @endif
</div>
