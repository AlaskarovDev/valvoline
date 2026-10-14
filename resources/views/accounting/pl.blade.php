@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-xs text-muted">{{ __('Rəsmi (vergi bəyannaməsi kodları ilə) gəlir və xərclər — mühasibat faylındakı kimi.') }}</span>
        <div class="ml-auto">@include('accounting._year')</div>
    </div>

    @if ($year)
        @php $incomeTotal = $income->sum('amount'); $expenseTotal = $expenses->sum('amount'); @endphp
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <x-kpi :label="__('Gəlirlər')" :value="Fmt::money($incomeTotal)" tone="pos"/>
            <x-kpi :label="__('Xərclər')" :value="Fmt::money($expenseTotal)" tone="neg"/>
            <x-kpi class="col-span-2 lg:col-span-1" :label="__('Hesabat dövründə mənfəət (zərər)')" :value="Fmt::money($result->amount ?? $incomeTotal - $expenseTotal)"
                   :tone="($result->amount ?? $incomeTotal - $expenseTotal) < 0 ? 'neg' : 'pos'" :big="true"/>
        </div>

        <section class="card overflow-hidden" x-data="{ open: {} }">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ __('Mənfəət-zərər (vergi bəyannaməsi) illər üzrə') }}</h2>
                    <div class="card-sub">{{ __('İllər sütunlarda. Sətirlərə klik — vergi bəyannaməsi kodları ilə maddələr açılır.') }} {{ __('Rəqəmə klik — detal.') }}</div>
                </div>
            </div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr>
                        <th class="sticky left-0 z-[2] min-w-36 bg-surface-2 sm:min-w-72">{{ __('Göstərici') }}</th>
                        @foreach ($statement['columns'] as $y)<th @class(['num', 'bg-primary-soft' => $y === $year])>{{ $statement['labels'][$y] }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($statement['lines'] as $i => $line)
                        @php
                            $rowClass = match ($line['style']) { 'subtotal' => 'font-semibold', 'total' => 'font-bold', 'muted' => 'text-muted', default => '' };
                            $expandable = $line['style'] === 'expand' && count($line['children']) > 0;
                            $fmt = fn ($v) => $line['type'] === 'pct' ? Fmt::pct($v) : Fmt::money($v, 0, false);
                        @endphp
                        <tr class="{{ $rowClass }}" @if ($expandable) @click="if (! $event.target.closest('a')) open[{{ $i }}] = !open[{{ $i }}]" style="cursor: pointer" @endif>
                            <td @class(['sticky left-0 z-[1] whitespace-normal bg-surface', 'border-t-2 border-line-strong' => $line['style'] === 'total'])>
                                @if ($expandable)<span class="inline-block w-4 text-faint" x-text="open[{{ $i }}] ? '▾' : '▸'">▸</span>@endif
                                {{ $line['label'] }}
                            </td>
                            @foreach ($statement['columns'] as $y)
                                @php $v = $line['values'][$y] ?? 0; @endphp
                                <td @class(['num', 'border-t-2 border-line-strong' => $line['style'] === 'total', 'neg' => in_array($line['style'], ['total', 'subtotal'], true) && $v < 0, 'bg-primary-soft' => $y === $year])>
                                    @if (! empty($line['kind']))
                                        <a href="{{ route('accounting.pl.detail', ['year' => $y, 'kind' => $line['kind']]) }}" data-modal class="pnl-cell">{{ $fmt($v) }}</a>
                                    @else
                                        {{ $fmt($v) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                        @foreach ($line['children'] as $child)
                            <tr x-show="open[{{ $i }}]" x-cloak class="text-[12px] text-muted">
                                <td class="sticky left-0 z-[1] whitespace-normal bg-surface pl-9">{{ $child['label'] }}</td>
                                @foreach ($statement['columns'] as $y)
                                    <td @class(['num', 'bg-primary-soft' => $y === $year])>
                                        @if (abs($child['values'][$y] ?? 0) > 0.005)
                                            <a href="{{ route('accounting.pl.detail', ['year' => $y, 'code' => $child['code'], 'name' => $child['name']]) }}" data-modal class="pnl-cell">{{ Fmt::money($child['values'][$y], 0, false) }}</a>
                                        @else
                                            {{ Fmt::money(0, 0, false) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line px-4 py-2.5 text-xs text-muted">
                {{ __('Məbləğlər AZN, mühasibat faylındakı MZ vərəqindən. «Malın dəyəri» — 221.4 və re-eksportla bağlı xərclər (232.1); «Amortizasiya» — 227 / 228; qalan xərclər — «Əməliyyat xərcləri». Seçilmiş il rəngli sütundadır.') }}
            </div>
        </section>

        @if ($comparison)
            <section class="card overflow-hidden">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">{{ __('İdarəetmə hesabatı ilə müqayisə (:year, VALVO)', ['year' => $year]) }}</h2>
                        <div class="card-sub">{{ __('Sistemdəki satış / maya / xərc rəqəmləri (Excel importları) — rəsmi rəqəmlərlə fərq uçot üsulundan (ƏDV, re-eksport, kassa xərcləri) yarana bilər.') }}</div>
                    </div>
                    <a href="{{ route('pnl.index', ['from' => $year.'-01-01', 'to' => $year.'-12-31', 'gran' => 'year']) }}" class="btn btn-sm">{{ __('P&L-ə keç') }} →</a>
                </div>
                <div class="grid grid-cols-2 gap-px bg-line md:grid-cols-5">
                    @foreach ([__('Xalis satış') => $comparison['net_sales'], __('Maya') => $comparison['cogs'], __('Brüt mənfəət') => $comparison['gross_profit'],
                               __('Xərclər (kassa + bank)') => $comparison['expenses'], __('Xalis mənfəət') => $comparison['net_profit']] as $label => $value)
                        <div class="bg-surface p-4">
                            <div class="kpi-label">{{ $label }}</div>
                            <div class="mt-1 text-lg font-semibold tabular-nums {{ $value < 0 ? 'neg' : '' }}">{{ Fmt::money($value) }}</div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endif
</x-layouts.app>
