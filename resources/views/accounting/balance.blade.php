@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-3">
        @if ($asOf)<span class="badge badge-primary">{{ __(':date tarixinə', ['date' => Fmt::date($asOf)]) }}</span>@endif
        <span class="text-xs text-muted">{{ __('Köhnə hesab nömrələri faylda olduğu kimi; yanında yeni Hesablar Planı üzrə hesab.') }}</span>
        <div class="ml-auto">@include('accounting._year')</div>
    </div>
    @include('accounting._balance-tabs', ['view' => 'official'])

    @if ($year)
        @php $totalA = $assets->sum('amount'); $totalP = $liabilities->sum('amount'); @endphp
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <x-kpi :label="__('Aktivlər')" :value="Fmt::money($totalA)"/>
            <x-kpi :label="__('Öhdəliklər və kapital')" :value="Fmt::money($totalP)"/>
            <x-kpi class="col-span-2 lg:col-span-1" :label="__('Balans yoxlaması')" :value="abs($totalA - $totalP) < 0.01 ? __('Bərabərdir ✓') : Fmt::money($totalA - $totalP)" :tone="abs($totalA - $totalP) < 0.01 ? 'pos' : 'neg'"/>
        </div>

        <div class="grid grid-cols-1 gap-4 2xl:grid-cols-2">
            @foreach ([__('Aktivlər') => $assets, __('Öhdəliklər və kapital') => $liabilities] as $heading => $list)
                <section class="card overflow-hidden">
                    <div class="card-head"><h2 class="card-title">{{ $heading }}</h2><span class="card-sub">{{ __(':n sətir', ['n' => $list->count()]) }}</span></div>
                    <div class="tbl-wrap">
                        <table class="tbl">
                            <thead><tr><th>{{ __('Köhnə') }}</th><th>{{ __('Hesab') }}</th><th>{{ __('Yeni hesab') }}</th><th class="num">{{ __('Məbləğ') }}</th></tr></thead>
                            <tbody>
                            @foreach ($list as $line)
                                <tr @class(['text-faint' => abs($line->amount) < 0.005])>
                                    <td class="font-mono">{{ $line->legacy_code }}</td>
                                    <td class="wrap">
                                        @if (isset($ledgerCodes[$line->legacy_code]))
                                            <a href="{{ route('accounting.ledger', ['code' => $line->legacy_code, 'year' => $year]) }}" class="cell-link" data-modal>{{ $line->name }}</a>
                                        @else
                                            {{ $line->name }}
                                        @endif
                                    </td>
                                    <td class="wrap text-xs">
                                        @if ($line->chart_code)<span class="font-mono font-semibold">{{ $line->chart_code }}</span> <span class="text-muted">{{ $line->chart_name }}</span>@else — @endif
                                    </td>
                                    <td class="num {{ $line->amount < 0 ? 'neg' : '' }}">{{ Fmt::money($line->amount) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot><tr><td colspan="3">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($list->sum('amount')) }}</td></tr></tfoot>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
        <div class="text-xs text-muted">{{ __('Mənfi məbləğlər faylda olduğu kimidir (məs. 68 «Büdcə ilə hesablaşmalar» mənfi — büdcəyə artıq ödəniş). Altından xətt çəkilmiş hesaba klik — kontragentlər üzrə qalıq.') }}</div>
    @endif
</x-layouts.app>
