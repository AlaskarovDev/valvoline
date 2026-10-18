@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-xs text-muted">{{ __('Hesablar mühasibat faylındakı ay sonu qalıqlarından avtomatik yaradılır. Əməliyyatlar bank çıxarışından (Excel) import olunur və ya əl ilə əlavə edilir.') }}</span>
        @if (count($years) > 0)
            <div class="ml-auto">@include('accounting._export')</div>
            <form method="GET" class="flex items-center gap-2 text-xs text-muted">
                <label for="bank-year">{{ __('İl') }}</label>
                <select id="bank-year" name="year" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
                    @foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>@endforeach
                </select>
            </form>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Pul vəsaitləri cəmi')" :value="Fmt::money($latestTotal)" :meta="$latestPeriod ? __(':period sonuna, manat ekvivalenti', ['period' => Fmt::period($latestPeriod)]) : __('Mühasibat faylı yüklənməyib')" :big="true"/>
        <x-kpi :label="__('Hesab sayı')" :value="$accounts->where('is_active', true)->count()" :meta="__(':n bank', ['n' => $accounts->pluck('bank')->filter()->unique()->count()])"/>
        <x-kpi :label="__('Çıxarışdan əməliyyat')" :value="Fmt::number($accounts->sum(fn ($a) => $a->ops->n ?? 0))" :meta="__('bütün hesablar üzrə')"/>
        <x-kpi :label="__('Cash Flow (bank)')" :value="__('Bax')" :href="route('accounting.cashflow', ['year' => $year])" :meta="__('kontragent × ay, ödənişlərin növü')"/>
    </div>

    <section class="card overflow-hidden">
        <div class="card-head"><h2 class="card-title">{{ __('Hesablar') }}</h2><span class="card-sub">{{ __('Qalıq — mühasibat faylındakı son ay sonu') }}</span></div>
        <ul class="divide-y divide-line">
            @foreach ($accounts as $a)
                <li>
                    <a href="{{ route('bank.show', $a->id) }}" @class(['grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-surface-2 sm:grid-cols-[minmax(0,1fr)_140px_160px_auto]', 'opacity-60' => ! $a->is_active])>
                        <div class="min-w-0">
                            <div class="font-medium">{{ $a->name }}</div>
                            <div class="text-xs text-muted">{{ $a->bank ?: '—' }} · {{ $kinds[$a->kind] ?? $a->kind }}@if ($a->iban) · <span class="font-mono">{{ $a->iban }}</span>@endif</div>
                        </div>
                        <div class="hidden text-xs text-muted sm:block">
                            @if ($a->ops){{ __(':n əməliyyat', ['n' => $a->ops->n]) }}<br>{{ __('son') }}: {{ Fmt::date($a->ops->last_date) }}@else{{ __('çıxarış yoxdur') }}@endif
                        </div>
                        @php $stale = $a->latest && $a->latest->period !== $latestPeriod; @endphp
                        <div class="text-right" @if ($stale) title="{{ __('Sonrakı aylarda faylda yoxdur — cəmə daxil deyil') }}" @endif>
                            <div @class(['font-semibold tabular-nums', 'text-muted' => $stale])>{{ $a->latest ? Fmt::money($a->latest->amount) : '—' }}</div>
                            <div class="text-xs text-muted">@if ($a->latest){{ $stale ? __('son məlumat :period · cəmə daxil deyil', ['period' => Fmt::period($a->latest->period)]) : Fmt::period($a->latest->period) }}@endif @if ($a->currency !== 'AZN')· {{ __('AZN ekv.') }}@endif</div>
                        </div>
                        <span class="badge {{ $a->currency === 'AZN' ? '' : 'badge-primary' }} justify-self-end">{{ $a->currency }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($months)
        <x-chart :title="__('Ay sonu qalıqları hesab üzrə')" :sub="__('mühasibat faylı, manat ekvivalenti')" :spec="$chart" :height="300"/>

        <section class="card overflow-hidden">
            <div class="card-head"><h2 class="card-title">{{ __('Ay sonu qalıqları') }} — {{ $year }}</h2></div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th class="sticky left-0 z-[2] min-w-48 bg-surface-2">{{ __('Hesab') }}</th>@foreach ($months as $m)<th class="num">{{ Fmt::period($m) }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($accounts->filter(fn ($a) => $a->monthly !== []) as $a)
                        <tr>
                            <td class="sticky left-0 z-[1] bg-surface"><a href="{{ route('bank.show', ['account' => $a->id, 'year' => $year]) }}" class="cell-link">{{ $a->name }}</a></td>
                            @foreach ($months as $m)
                                @php $v = $a->monthly[$m] ?? null; @endphp
                                <td class="num {{ $v === null ? 'text-faint' : ($v < 0 ? 'neg' : '') }}">{{ $v === null ? '—' : Fmt::money($v, 0, false) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr><td class="sticky left-0 z-[1] bg-surface-2">{{ __('Cəmi') }}</td>@foreach ($months as $m)<td class="num">{{ Fmt::money($totals[$m] ?? 0, 0, false) }}</td>@endforeach</tr></tfoot>
                </table>
            </div>
        </section>
    @endif

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="card overflow-hidden">
            <div class="card-head"><h2 class="card-title">{{ __('Son əməliyyatlar') }}</h2></div>
            <ul class="divide-y divide-line text-sm">
                @forelse ($recent as $t)
                    <li class="flex items-start justify-between gap-3 px-4 py-2.5">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $t->counterparty ?: '—' }}</div>
                            <div class="text-xs text-muted">{{ Fmt::date($t->date) }} · {{ $t->account_name }}</div>
                        </div>
                        <div class="shrink-0 font-semibold tabular-nums {{ $t->amount_in > 0 ? 'pos' : 'neg' }}">{{ $t->amount_in > 0 ? '+'.Fmt::money($t->amount_in) : '−'.Fmt::money($t->amount_out) }}</div>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-muted">{{ __('Hələ əməliyyat yoxdur. Hesabı açın və bank çıxarışını yükləyin.') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Yeni hesab') }}</h2></div>
            <form method="POST" action="{{ route('bank.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
                @csrf
                <div class="sm:col-span-2"><label class="label" for="ba-name">{{ __('Ad') }}</label><input id="ba-name" name="name" required class="input" placeholder="PAŞA Bank AZN" value="{{ old('name') }}"></div>
                <div><label class="label" for="ba-bank">{{ __('Bank') }}</label><input id="ba-bank" name="bank" class="input" value="{{ old('bank') }}"></div>
                <div><label class="label" for="ba-iban">IBAN</label><input id="ba-iban" name="iban" class="input font-mono" value="{{ old('iban') }}"></div>
                <div><label class="label" for="ba-cur">{{ __('Valyuta') }}</label>
                    <select id="ba-cur" name="currency" class="select">@foreach (['AZN', 'USD', 'EUR', 'RUB', 'GBP', 'TRY'] as $c)<option>{{ $c }}</option>@endforeach</select></div>
                <div><label class="label" for="ba-kind">{{ __('Növ') }}</label>
                    <select id="ba-kind" name="kind" class="select">@foreach ($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
                <div class="sm:col-span-2"><button class="btn btn-primary">{{ __('Əlavə et') }}</button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
