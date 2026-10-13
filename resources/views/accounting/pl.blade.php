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

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ([__('Gəlirlər') => $income, __('Xərclər') => $expenses] as $heading => $list)
                <section class="card overflow-hidden">
                    <div class="card-head"><h2 class="card-title">{{ $heading }}</h2></div>
                    <div class="tbl-wrap">
                        <table class="tbl">
                            <thead><tr><th>{{ __('Kod') }}</th><th>{{ __('Maddə') }}</th><th class="num">{{ __('Məbləğ') }}</th><th class="num">{{ __('Pay') }}</th></tr></thead>
                            <tbody>
                            @php $sum = $list->sum('amount'); @endphp
                            @foreach ($list as $line)
                                <tr>
                                    <td class="font-mono text-xs">{{ $line->tax_code }}</td>
                                    <td class="wrap">{{ $line->name }}</td>
                                    <td class="num {{ $line->amount < 0 ? 'neg' : '' }}">{{ Fmt::money($line->amount) }}</td>
                                    <td class="num text-muted">{{ $sum != 0 ? Fmt::pct($line->amount / $sum) : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot><tr><td colspan="2">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($sum) }}</td><td></td></tr></tfoot>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>

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
