@php
    use App\Support\Fmt;
    $totalA = $assets->sum('amount');
    $totalP = $liabilities->sum('amount');
@endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-3">
        <span class="badge badge-primary">{{ __(':date tarixinə', ['date' => Fmt::date($date)]) }}</span>
        <span class="text-xs text-muted">{{ __('Sistem importlarından (satış, qaytarma, ödəniş, kassa, anbar) qurulmuş balans. «Rəsmi» sütunu — müqayisə üçün mühasibat balansındakı eyni hesab.') }}</span>
        <div class="ml-auto flex items-center gap-2">@if (count($years) > 0)@include('accounting._year')@endif @include('accounting._export')</div>
    </div>
    @include('accounting._balance-tabs', ['view' => 'mgmt'])

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        <x-kpi :label="__('Aktivlər')" :value="Fmt::money($totalA)"/>
        <x-kpi :label="__('Öhdəliklər və kapital')" :value="Fmt::money($totalP)"/>
        <x-kpi class="col-span-2 lg:col-span-1" :label="__('Balans yoxlaması')" :value="abs($totalA - $totalP) < 0.01 ? __('Bərabərdir ✓') : Fmt::money($totalA - $totalP)" :tone="abs($totalA - $totalP) < 0.01 ? 'pos' : 'warn'"/>
    </div>

    <div class="grid grid-cols-1 gap-4 2xl:grid-cols-2">
        @foreach ([__('Aktivlər') => $assets, __('Öhdəliklər və kapital') => $liabilities] as $heading => $list)
            <section class="card overflow-hidden">
                <div class="card-head"><h2 class="card-title">{{ $heading }}</h2></div>
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>{{ __('Hesab') }}</th><th>{{ __('Ad') }}</th><th class="num">{{ __('İdarəetmə') }}</th><th class="num">{{ __('Rəsmi') }}</th></tr></thead>
                        <tbody>
                        @foreach ($list as $line)
                            <tr>
                                <td class="font-mono font-semibold">{{ $line->code }}</td>
                                <td class="wrap">
                                    {{ $line->name }}
                                    @if (count($line->parts) === 1)<div class="text-xs text-muted">{{ $line->parts[0]['label'] }}</div>@endif
                                </td>
                                <td class="num font-semibold {{ $line->amount < 0 ? 'neg' : '' }}">
                                    <a href="{{ route('accounting.chart.mgmt', ['code' => $line->code, 'year' => $year]) }}" data-modal class="cell-link">{{ Fmt::money($line->amount) }}</a>
                                </td>
                                <td class="num text-muted">{{ $line->official === null ? '—' : Fmt::money($line->official) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr><td colspan="2">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($list->sum('amount')) }}</td><td></td></tr></tfoot>
                    </table>
                </div>
            </section>
        @endforeach
    </div>
    <div class="text-xs text-muted">
        {{ __('205 — anbar qalığı :method mayası ilə; 211 / 542 — müştəri borcları / avansları; 221 — sistemin kassası. 343 — əvvəlki illərin idarəetmə xalis mənfəəti, 341 — cari ilin xalis mənfəəti, 344 — təsisçiyə ödənişlər (bütün illər, kapitalı azaldır). 301 «Keçmiş illərin mənfəəti» — aktiv ilə passiv fərqi: importlardan əvvəlki dövrün mənfəəti və importlarda olmayan qalıqlar (bank, malsatan borcları). Məbləğə klik — necə yarandığı.', ['method' => \App\Services\CostingService::methodLabel()]) }}
    </div>
</x-layouts.app>
