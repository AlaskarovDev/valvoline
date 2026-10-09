@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['direction', 'manager', 'category']" :own="['gran']">
        <label class="sr-only" for="gran">Dövr</label>
        <select id="gran" name="gran" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
            <option value="month" @selected($granularity === 'month')>Aylıq</option>
            <option value="year" @selected($granularity === 'year')>İllik</option>
        </select>
    </x-filters>

    <x-chart title="Brüt mənfəət, xərclər və xalis mənfəət" :sub="$filters->label().' · xərclər: '.($filters->direction && $filters->direction !== 'all' ? $filters->direction : 'bütün istiqamətlər')" :spec="$chart" :height="300"/>

    <section class="card overflow-hidden" x-data="{ open: {} }">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
            <div>
                <h2 class="card-title">Mənfəət-zərər hesabatı</h2>
                <div class="card-sub">{{ $granularity === 'year' ? 'İllər' : 'Aylar' }} sütunlarda, cəmi sonda. Xərc və gəlir sətirlərinə klik — kateqoriyalar açılır.</div>
            </div>
            <div class="ml-auto flex gap-1.5 no-print">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-sm"><x-icon name="download" size="14"/> Excel</a>
                <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-sm"><x-icon name="download" size="14"/> PDF</a>
            </div>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                <tr>
                    <th class="sticky left-0 z-[2] min-w-36 bg-surface-2 sm:min-w-56">Göstərici</th>
                    @foreach ($labels as $label)<th class="num">{{ $label }}</th>@endforeach
                    <th class="num">Cəmi</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($lines as $i => $line)
                    @php
                        $rowClass = match ($line['style']) { 'subtotal' => 'font-semibold', 'total' => 'font-bold', 'muted' => 'text-muted', default => '' };
                        $expandable = $line['style'] === 'expand' && count($line['children']) > 0;
                    @endphp
                    <tr class="{{ $rowClass }}" @if($expandable) @click="open[{{ $i }}] = !open[{{ $i }}]" style="cursor: pointer" @endif>
                        <td class="sticky left-0 z-[1] whitespace-normal bg-surface {{ $line['style'] === 'total' ? 'border-t-2 border-line-strong' : '' }}">
                            @if ($expandable)<span class="inline-block w-4 text-faint" x-text="open[{{ $i }}] ? '▾' : '▸'">▸</span>@endif
                            {{ $line['label'] }}
                        </td>
                        @foreach ($columns as $c)
                            @php $v = $line['values'][$c] ?? 0; @endphp
                            <td class="num {{ $line['style'] === 'total' ? 'border-t-2 border-line-strong' : '' }} {{ in_array($line['style'], ['total', 'subtotal'], true) && $v < 0 ? 'neg' : '' }}">
                                {{ $line['type'] === 'pct' ? Fmt::pct($v) : Fmt::money($v, 0, false) }}
                            </td>
                        @endforeach
                        <td class="num font-semibold {{ $line['style'] === 'total' ? 'border-t-2 border-line-strong' : '' }} {{ ($line['total'] ?? 0) < 0 && in_array($line['style'], ['total', 'subtotal'], true) ? 'neg' : '' }}">
                            {{ $line['type'] === 'pct' ? Fmt::pct($line['total']) : Fmt::money($line['total'], 0, false) }}
                        </td>
                    </tr>
                    @foreach ($line['children'] as $child)
                        <tr x-show="open[{{ $i }}]" x-cloak class="text-[12px] text-muted">
                            <td class="sticky left-0 z-[1] bg-surface pl-9">{{ $child['label'] }}</td>
                            @foreach ($columns as $c)<td class="num">{{ Fmt::money($child['values'][$c] ?? 0, 0, false) }}</td>@endforeach
                            <td class="num">{{ Fmt::money(array_sum($child['values']), 0, false) }}</td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="border-t border-line px-4 py-2.5 text-xs text-muted">
            Məbləğlər AZN, tam ədədə yuvarlaqlaşdırılıb (hesablama 4 onluq dəqiqliklə). Hədiyyə / daxili istifadə mayası brüt mənfəəti azaldır. «Mənfəətə təsir: Xeyr» olan kassa əməliyyatları burada görünmür — yalnız Kassa hesabatında.
        </div>
    </section>
</x-layouts.app>
