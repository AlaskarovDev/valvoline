@php
    use App\Services\ReconciliationService as R;
    use App\Support\Fmt;
    $statusBadge = [
        R::STATUS_ADDED => ['badge-primary', __('Sistemdə yox idi → rəsmi kimi əlavə olundu')],
        R::STATUS_BOTH => ['badge-warn', __('Həm kassada, həm bankda — təkrar olmadığını yoxlayın')],
        R::STATUS_CASH_ONLY => ['', __('Yalnız kassada (rəsmi uçotda yoxdur)')],
        R::STATUS_OK => ['badge-pos', __('Uyğundur')],
        R::STATUS_INFO => ['', __('Məlumat')],
    ];
@endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-xs text-muted">{{ __('Sistemdəki Excel importları (satış, ödəniş, kassa, alış) mühasibat faylı (MZ, Cash Flow) ilə müqayisə olunur.') }}</span>
        <div class="ml-auto flex items-center gap-2">@include('accounting._year') @if ($year)@include('accounting._export')@endif</div>
    </div>

    @if ($year)
        @php
            $added = collect($expenses)->where('status', R::STATUS_ADDED);
            $addedTotal = collect($expenses)->sum('bank');
            $exportRow = collect($revenue)->firstWhere('key', 'export');
        @endphp
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-kpi :label="__('Rəsmi xərclər sistemə əlavə olundu')" :value="Fmt::money($addedTotal)" tone="neg"
                   :meta="__(':n qrup yalnız mühasibatda idi', ['n' => $added->count()])" :href="route('expenses.index', ['preset' => 'custom', 'from' => $year.'-01-01', 'to' => $year.'-12-31', 'src' => 'bank'])"/>
            <x-kpi :label="__('Re-eksport gəliri (rəsmi)')" :value="Fmt::money($exportRow->official ?? 0)" tone="pos"
                   :meta="__('satış faylında yox idi → P&L-ə əlavə olundu')" :href="route('pnl.index', ['preset' => 'custom', 'from' => $year.'-01-01', 'to' => $year.'-12-31', 'gran' => 'year'])"/>
            <x-kpi :label="__('Kassa xərcləri')" :value="Fmt::money(collect($expenses)->sum('cash'))" :meta="__('08 Xərclər faylı, VALVO')"/>
            <x-kpi :label="__('MZ xərcləri (hesablanmış)')" :value="Fmt::money(collect($expenses)->sum('mz'))" :meta="__('mal dəyəri və re-eksport xaric')"/>
        </div>

        <section class="card overflow-hidden">
            <div class="card-head"><div>
                <h2 class="card-title">{{ __('Gəlirlər') }}</h2>
                <div class="card-sub">{{ __('Sistem: satış və ödəniş faylları. Rəsmi: MZ və Cash Flow.') }}</div>
            </div></div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th class="min-w-56">{{ __('Göstərici') }}</th><th class="num">{{ __('Sistem') }}</th><th class="num">{{ __('Rəsmi') }}</th><th class="num">{{ __('Fərq') }}</th><th class="min-w-80">{{ __('Şərh') }}</th></tr></thead>
                    <tbody>
                    @foreach ($revenue as $r)
                        <tr>
                            <td class="wrap font-medium">{{ $r->label }}
                                @if ($r->status === R::STATUS_ADDED)<div><span class="badge badge-primary">{{ __('P&L-ə əlavə olundu') }}</span></div>@endif
                            </td>
                            <td class="num">{{ Fmt::money($r->system) }}</td>
                            <td class="num">{{ Fmt::money($r->official) }}</td>
                            <td class="num {{ $r->official - $r->system < 0 ? 'neg' : '' }}">{{ Fmt::money($r->official - $r->system) }}</td>
                            <td class="wrap text-xs text-muted">{{ $r->note }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card overflow-hidden">
            <div class="card-head"><div>
                <h2 class="card-title">{{ __('Xərclər qrup üzrə') }}</h2>
                <div class="card-sub">{{ __('Kassa — sistemdəki 08 faylı; Rəsmi (bank) — mühasibat Cash Flow ödənişləri; MZ — vergi bəyannaməsindəki hesablanmış xərc (müqayisə üçün).') }}</div>
            </div></div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th class="min-w-48">{{ __('Qrup') }}</th><th class="num">{{ __('Kassa') }}</th><th class="num">{{ __('Rəsmi (bank)') }}</th><th class="num">{{ __('Sistemdə indi') }}</th><th class="num">{{ __('MZ (hesablanmış)') }}</th><th class="min-w-72">{{ __('Vəziyyət') }}</th></tr></thead>
                    <tbody>
                    @foreach ($expenses as $e)
                        <tr>
                            <td class="wrap font-medium">{{ $e->label }}
                                @if ($e->categories)<div class="text-xs font-normal text-muted">{{ __('kassa') }}: {{ implode(', ', $e->categories) }}</div>@endif
                            </td>
                            <td class="num {{ $e->cash == 0 ? 'text-faint' : '' }}">{{ $e->cash == 0 ? '—' : Fmt::money($e->cash) }}</td>
                            <td class="num {{ $e->bank == 0 ? 'text-faint' : '' }}">{{ $e->bank == 0 ? '—' : Fmt::money($e->bank) }}</td>
                            <td class="num font-semibold">{{ Fmt::money($e->system_after) }}</td>
                            <td class="num {{ $e->mz == 0 ? 'text-faint' : 'text-muted' }}">{{ $e->mz == 0 ? '—' : Fmt::money($e->mz) }}</td>
                            <td class="wrap"><span class="badge {{ $statusBadge[$e->status][0] }}">{{ $statusBadge[$e->status][1] }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr>
                        <td>{{ __('Cəmi') }}</td>
                        <td class="num">{{ Fmt::money(collect($expenses)->sum('cash')) }}</td>
                        <td class="num">{{ Fmt::money(collect($expenses)->sum('bank')) }}</td>
                        <td class="num">{{ Fmt::money(collect($expenses)->sum('system_after')) }}</td>
                        <td class="num">{{ Fmt::money(collect($expenses)->sum('mz')) }}</td>
                        <td></td>
                    </tr></tfoot>
                </table>
            </div>
            <div class="border-t border-line px-4 py-2.5 text-xs text-muted">
                {{ __('Mal alışı, gömrük, vergi, təsisçiyə ödəniş və əsas vəsait alışı xərc sayılmır — onlar Cash Flow səhifəsində ayrıca növlərdədir. Amortizasiya pul hərəkəti olmadığı üçün yalnız MZ-də görünür.') }}
            </div>
        </section>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <section class="card overflow-hidden">
                <div class="card-head"><h2 class="card-title">{{ __('Alışlar və maya') }}</h2></div>
                <ul class="divide-y divide-line text-sm">
                    @foreach ($purchases as $p)
                        <li class="flex items-start justify-between gap-4 px-4 py-3">
                            <div class="min-w-0"><div class="font-medium">{{ $p->label }}</div><div class="text-xs text-muted">{{ $p->note }}</div></div>
                            <div class="shrink-0 font-semibold tabular-nums">{{ Fmt::money($p->amount) }}</div>
                        </li>
                    @endforeach
                </ul>
            </section>

            @include('accounting._openings')
        </div>

        @include('accounting._official-revenue')
    @endif
</x-layouts.app>
