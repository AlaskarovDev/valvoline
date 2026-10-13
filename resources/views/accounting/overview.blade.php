@php
    use App\Support\Fmt;
    $yearHead = fn ($y) => isset($yearLabels[$y]) && ! str_starts_with($yearLabels[$y], '31.12') ? __(':year (:date-ə qədər)', ['year' => $y, 'date' => $yearLabels[$y]]) : (string) $y;
    // dəyişmə yalnız tam illər arasında (natamam ili tam illə müqayisə etmək yanıldır)
    $fullYears = array_values(array_filter($years, fn ($y) => ! isset($yearLabels[$y]) || str_starts_with($yearLabels[$y], '31.12')));
    $deltaPair = count($fullYears) > 1 ? array_slice($fullYears, -2) : null;
    $sideLabels = ['asset' => __('Aktiv'), 'liability' => __('Passiv'), 'income' => __('Gəlirlər'), 'expense' => __('Xərclər')];
@endphp
<x-layouts.app :title="$title">
    @if (count($years) === 0)
        @include('accounting._year', ['year' => null])
    @else
        <div class="text-xs text-muted">{{ __('Hər ilin mühasibat faylı (Balans / MZ / Cash Flow) yan-yana.') }}
            @if ($deltaPair){{ __('«Dəyişmə» — :to ilinin :from ilinə nisbətən (yalnız tam illər).', ['to' => $deltaPair[1], 'from' => $deltaPair[0]]) }}@endif</div>

        <section class="card overflow-hidden">
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead>
                    <tr>
                        <th class="sticky left-0 z-[2] min-w-56 bg-surface-2">{{ __('Göstərici') }}</th>
                        @foreach ($years as $y)
                            <th class="num"><a href="{{ route('accounting.balance', ['year' => $y]) }}" class="hover:text-primary">{{ $yearHead($y) }}</a></th>
                        @endforeach
                        @if ($deltaPair)<th class="num">{{ __('Dəyişmə') }} {{ $deltaPair[1] }}/{{ $deltaPair[0] }}</th>@endif
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($groups as $heading => $metrics)
                        <tr><td colspan="{{ count($years) + 2 }}" class="bg-surface-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $heading }}</td></tr>
                        @foreach ($metrics as $m)
                            @php $delta = $deltaPair ? Fmt::delta($m->values[$deltaPair[1]], $m->values[$deltaPair[0]]) : null; @endphp
                            <tr @class(['font-semibold' => $m->strong])>
                                <td class="sticky left-0 z-[1] bg-surface {{ str_starts_with($m->label, '·') ? 'pl-6 text-muted' : '' }}">{{ $m->label }}</td>
                                @foreach ($m->values as $v)
                                    @php $tone = $m->tone === 'sign' ? ($v < 0 ? 'neg' : 'pos') : ($m->tone === 'warn' && $v > 0 ? 'warn' : ''); @endphp
                                    <td class="num {{ $tone }} {{ $v == 0 ? 'text-faint' : '' }}">{{ $v == 0 ? '—' : Fmt::money($v, 0) }}</td>
                                @endforeach
                                @if ($deltaPair)
                                    <td class="num text-xs {{ $delta === null ? 'text-faint' : ($delta < 0 ? 'neg' : 'pos') }}">{{ $delta === null ? '—' : ($delta > 0 ? '+' : '').Fmt::pct($delta) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <x-chart :title="__('Mənfəət-zərər (vergi) illər üzrə')" :spec="$chart" :height="300"/>

        @foreach ([__('Balans sətirləri illər üzrə') => $balanceRows, __('Mənfəət-zərər maddələri illər üzrə') => $plRows] as $heading => $list)
            <section class="card overflow-hidden">
                <div class="card-head"><h2 class="card-title">{{ $heading }}</h2></div>
                <div class="tbl-wrap max-h-[70vh]">
                    <table class="tbl">
                        <thead>
                        <tr>
                            <th>{{ __('Kod') }}</th>
                            <th class="min-w-64">{{ __('Maddə') }}</th>
                            @foreach ($years as $y)<th class="num">{{ $yearHead($y) }}</th>@endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($list->groupBy('section') as $section => $rows)
                            <tr><td colspan="{{ count($years) + 2 }}" class="bg-surface-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $sideLabels[$section] ?? $section }}</td></tr>
                            @foreach ($rows as $r)
                                <tr>
                                    <td class="font-mono text-xs">{{ $r->code }}</td>
                                    <td class="wrap">{{ $r->name }}</td>
                                    @foreach ($years as $y)
                                        @php $v = $r->values[$y] ?? null; @endphp
                                        <td class="num {{ $v === null || $v == 0 ? 'text-faint' : ($v < 0 ? 'neg' : '') }}">{{ $v === null || $v == 0 ? '—' : Fmt::money($v, 0) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            <tr class="font-semibold">
                                <td></td><td>{{ __('Cəmi') }}</td>
                                @foreach ($years as $y)<td class="num">{{ Fmt::money($rows->sum(fn ($r) => $r->values[$y] ?? 0), 0) }}</td>@endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    @endif
</x-layouts.app>
