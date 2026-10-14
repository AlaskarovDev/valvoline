@php
    use App\Support\Fmt;
    $natureLabels = ['A' => __('Aktiv'), 'P' => __('Passiv'), 'K' => __('Kontr-aktiv'), 'G' => __('Gəlir'), 'X' => __('Xərc'), 'N' => __('Nəticə'), 'B' => __('Balansdankənar')];
    $natureClass = ['A' => 'badge-primary', 'P' => 'badge-warn', 'K' => '', 'G' => 'badge-pos', 'X' => 'badge-neg', 'N' => '', 'B' => ''];
@endphp
<x-layouts.app :title="$title">
    <div class="card flex flex-wrap items-start gap-3 px-4 py-3 text-sm text-muted">
        <div class="min-w-0 flex-1">
        {{ __('Azərbaycan Respublikasında kommersiya təşkilatları üçün Mühasibat uçotu hesablarının Hesablar Planı (bölmə → maddə → hesab). Adlar redaktə oluna, yeni hesab əlavə edilə bilər — mühasibinizlə tutuşdurun.') }}
        @if ($year)
            {{ __('«Rəsmi» — mühasibat faylı: :date tarixinə balans, gəlir və xərc hesabları MZ-dən. «İdarəetmə» — sistem importları (satış, qaytarma, ödəniş, kassa, anbar) eyni tarixə; gəlir və xərclər ilin əvvəlindən. Məbləğə klik — necə yarandığı.', ['date' => $yearLabels[$year] ?? $year]) }}
        @endif
        </div>
        @if ($year)<div class="shrink-0">@include('accounting._year')</div>@endif
    </div>

    <form method="GET" class="card flex flex-wrap items-center gap-2 p-3">
        <div class="-mx-1 flex w-[calc(100%+0.5rem)] gap-1.5 overflow-x-auto px-1 pb-0.5 sm:w-auto sm:flex-wrap sm:overflow-visible">
            <a href="{{ route('accounting.chart', array_filter(['q' => $q, 'year' => $year])) }}" @class(['chip shrink-0', 'active' => $section === ''])>{{ __('Hamısı') }}</a>
            @foreach ($sections as $no => $name)
                <a href="{{ route('accounting.chart', array_filter(['section' => (string) $no, 'q' => $q, 'year' => (string) $year], fn ($v) => $v !== '')) }}" @class(['chip shrink-0', 'active' => $section === (string) $no])>{{ $no }} · {{ $name }}</a>
            @endforeach
        </div>
        <input type="hidden" name="section" value="{{ $section }}">
        <label class="flex items-center gap-1.5 text-xs text-muted"><input type="checkbox" name="only" value="1" @checked($only) onchange="this.form.submit()"> {{ __('Yalnız məbləği olanlar') }}</label>
        @if ($year)<input type="hidden" name="year" value="{{ $year }}">@endif
        <label class="sr-only" for="chart-q">{{ __('Axtarış') }}</label>
        <input id="chart-q" name="q" value="{{ $q }}" placeholder="{{ __('Hesab nömrəsi və ya adı…') }}" class="input h-[34px] min-w-0 flex-1 text-xs sm:h-[30px] sm:w-56 sm:flex-none">
        <button class="btn btn-sm btn-primary">{{ __('Tətbiq et') }}</button>
    </form>

    @php
        // qrup / bölmə cəmi: 602 və 603 (qaytarma, güzəşt) gəlirdən çıxılır
        $contra = \App\Services\ChartLedgerService::CONTRA_INCOME;
        $total = fn ($items, $values) => $items->sum(fn ($a) => isset($values[$a->code]) ? (in_array($a->code, $contra, true) ? -1 : 1) * (float) $values[$a->code] : 0.0);
        $has = fn ($items, $values) => $items->contains(fn ($a) => isset($values[$a->code]));
        $sumCell = fn ($items, $values) => $has($items, $values) ? Fmt::money($total($items, $values)) : '—';
    @endphp
    @forelse ($accounts as $sec => $list)
        <section class="card overflow-hidden">
            <div class="card-head">
                <h2 class="card-title">{{ __('Bölmə') }} {{ $sec }}. {{ $sections[$sec] ?? '' }}</h2>
                <span class="card-sub">{{ __(':n hesab', ['n' => $list->count()]) }}</span>
            </div>
            <div class="grid grid-cols-[minmax(0,1fr)_auto_auto] gap-x-4 border-b border-line bg-primary-soft px-4 py-2 text-sm font-semibold md:grid-cols-[56px_minmax(0,1fr)_100px_130px_130px_auto] md:gap-x-3">
                <span class="md:col-span-3">{{ __('Bölmə :n cəmi', ['n' => $sec]) }}</span>
                <span @class(['text-right tabular-nums', 'neg' => $total($list, $balances) < 0])>{{ $sumCell($list, $balances) }}</span>
                <span @class(['text-right tabular-nums', 'neg' => $total($list, $management) < 0])>{{ $sumCell($list, $management) }}</span>
                <span class="hidden w-[38px] md:block"></span>
            </div>
            <div class="hidden grid-cols-[56px_minmax(0,1fr)_100px_130px_130px_auto] gap-x-3 border-b border-line bg-surface-2 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted md:grid">
                <span>{{ __('Hesab') }}</span><span>{{ __('Ad') }}</span><span>{{ __('Xarakter') }}</span>
                <span class="text-right">{{ __('Rəsmi') }}</span><span class="text-right">{{ __('İdarəetmə') }}</span><span class="w-[38px]"></span>
            </div>
            <ul class="divide-y divide-line">
                @foreach ($list->groupBy('group_code') as $group => $items)
                    <li class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-x-4 bg-surface-2 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-muted md:grid-cols-[56px_minmax(0,1fr)_100px_130px_130px_auto] md:gap-x-3">
                        <span class="md:col-span-3">{{ $group }} · {{ $groups[$group] ?? '' }}</span>
                        <span @class(['text-right normal-case tabular-nums', 'text-ink' => $total($items, $balances) >= 0, 'neg' => $total($items, $balances) < 0])>{{ $sumCell($items, $balances) }}</span>
                        <span @class(['text-right normal-case tabular-nums', 'text-ink' => $total($items, $management) >= 0, 'neg' => $total($items, $management) < 0])>{{ $sumCell($items, $management) }}</span>
                        <span class="hidden w-[38px] md:block"></span>
                    </li>
                    @foreach ($items as $a)
                        <li class="grid grid-cols-[56px_minmax(0,1fr)] items-center gap-x-3 gap-y-1 px-4 py-2 text-sm md:grid-cols-[56px_minmax(0,1fr)_100px_130px_130px_auto]" x-data="{ edit: false }">
                            <span class="font-mono font-semibold {{ $a->is_active ? '' : 'text-faint line-through' }}">{{ $a->code }}</span>
                            <span class="min-w-0" x-show="!edit">{{ $a->name }} @if ($a->user_edited)<span class="badge">{{ __('əl ilə') }}</span>@endif</span>
                            <form x-show="edit" x-cloak method="POST" action="{{ route('accounting.chart.update', $a->id) }}" class="col-span-full flex flex-wrap items-center gap-2 md:col-span-4">
                                @csrf @method('PUT')
                                <label class="sr-only" for="acc-{{ $a->id }}">{{ __('Ad') }}</label>
                                <input id="acc-{{ $a->id }}" name="name" value="{{ $a->name }}" class="input h-[30px] min-w-0 flex-1 text-xs">
                                <select name="is_active" class="select h-[30px] w-28 text-xs" aria-label="{{ __('Status') }}">
                                    <option value="1" @selected($a->is_active)>{{ __('Aktiv') }}</option>
                                    <option value="0" @selected(! $a->is_active)>{{ __('İstifadədə deyil') }}</option>
                                </select>
                                <button class="btn btn-sm btn-primary">{{ __('Saxla') }}</button>
                                <button type="button" class="btn btn-sm" @click="edit = false">{{ __('Ləğv et') }}</button>
                            </form>
                            <span class="col-start-2 md:col-start-auto" x-show="!edit"><span class="badge {{ $natureClass[$a->nature] ?? '' }}">{{ $natureLabels[$a->nature] ?? $a->nature }}</span></span>
                            <span class="col-start-2 flex justify-between gap-2 text-right tabular-nums md:col-start-auto md:block {{ isset($balances[$a->code]) && $balances[$a->code] < 0 ? 'neg' : '' }}" x-show="!edit">
                                <span class="text-xs text-faint md:hidden">{{ __('Rəsmi') }}</span>
                                @isset($balances[$a->code])
                                    <a href="{{ route('accounting.chart.source', ['code' => $a->code, 'year' => $year]) }}" data-modal
                                       class="underline decoration-dotted decoration-1 underline-offset-4 hover:text-primary">{{ Fmt::money($balances[$a->code]) }}</a>
                                @else<span class="text-faint">—</span>@endisset
                            </span>
                            <span class="col-start-2 flex justify-between gap-2 text-right tabular-nums md:col-start-auto md:block {{ isset($management[$a->code]) && $management[$a->code] < 0 ? 'neg' : '' }}" x-show="!edit">
                                <span class="text-xs text-faint md:hidden">{{ __('İdarəetmə') }}</span>
                                @isset($management[$a->code])
                                    <a href="{{ route('accounting.chart.mgmt', ['code' => $a->code, 'year' => $year]) }}" data-modal
                                       class="font-medium text-primary-text underline decoration-dotted decoration-1 underline-offset-4 hover:text-primary">{{ Fmt::money($management[$a->code]) }}</a>
                                @else<span class="text-faint">—</span>@endisset
                            </span>
                            <button type="button" class="btn btn-sm col-start-2 justify-self-start md:col-start-auto" x-show="!edit" @click="edit = true" aria-label="{{ __('Redaktə') }}"><x-icon name="edit" size="13"/></button>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </section>
    @empty
        <div class="card px-4 py-10 text-center text-sm text-muted">{{ __('Tapılmadı') }}</div>
    @endforelse

    {{-- mənfəət: gəlirlər (60–64) − xərclər (70–76) --}}
    <section class="card overflow-hidden">
        <div class="card-head"><div>
            <h2 class="card-title">{{ __('Mənfəət (zərər): Bölmə 6 − Bölmə 7') }}</h2>
            <div class="card-sub">{{ __('Gəlirlər (601, 611, 621, 631, 641; qaytarma 602 və güzəşt 603 çıxılır) − xərclər (701–761). Mənfəət vergisindən (Bölmə 9) əvvəl.') }}</div>
        </div></div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th></th><th class="num">{{ __('Rəsmi') }}</th><th class="num">{{ __('İdarəetmə') }}</th></tr></thead>
                <tbody>
                <tr><td>{{ __('Bölmə 6 — Gəlirlər') }}</td><td class="num pos">{{ Fmt::money($profit['official']['income']) }}</td><td class="num pos">{{ Fmt::money($profit['management']['income']) }}</td></tr>
                <tr><td>{{ __('− Bölmə 7 — Xərclər') }}</td><td class="num neg">{{ Fmt::money(-$profit['official']['expense']) }}</td><td class="num neg">{{ Fmt::money(-$profit['management']['expense']) }}</td></tr>
                </tbody>
                <tfoot><tr class="text-base">
                    <td>{{ __('= Mənfəət (zərər)') }}</td>
                    <td @class(['num', 'neg' => $profit['official']['result'] < 0, 'pos' => $profit['official']['result'] >= 0])>{{ Fmt::money($profit['official']['result']) }}</td>
                    <td @class(['num', 'neg' => $profit['management']['result'] < 0, 'pos' => $profit['management']['result'] >= 0])>{{ Fmt::money($profit['management']['result']) }}</td>
                </tr></tfoot>
            </table>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Yeni hesab') }}</h2></div>
            <form method="POST" action="{{ route('accounting.chart.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-[110px_1fr_150px_auto] sm:items-end">
                @csrf
                <div><label class="label" for="new-code">{{ __('Hesab') }}</label><input id="new-code" name="code" value="{{ old('code') }}" required pattern="\d{3,6}" class="input font-mono" placeholder="2051"></div>
                <div><label class="label" for="new-name">{{ __('Ad') }}</label><input id="new-name" name="name" value="{{ old('name') }}" required class="input"></div>
                <div><label class="label" for="new-nature">{{ __('Xarakter') }}</label>
                    <select id="new-nature" name="nature" class="select">@foreach ($natureLabels as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <button class="btn btn-primary">{{ __('Əlavə et') }}</button>
            </form>
        </section>

        <section class="card overflow-hidden">
            <div class="card-head">
                <div><h2 class="card-title">{{ __('Köhnə hesab nömrələri → yeni plan') }}</h2>
                    <div class="card-sub">{{ __('1C balans faylındakı köhnə (sovet tipli) nömrələrin uyğunluğu') }}</div></div>
            </div>
            <div class="tbl-wrap max-h-96">
                <table class="tbl">
                    <thead><tr><th>{{ __('Köhnə') }}</th><th>{{ __('Yeni') }}</th><th>{{ __('Ad') }}</th></tr></thead>
                    <tbody>
                    @foreach ($legacy as $l)
                        <tr>
                            <td class="font-mono">{{ $l->code }}</td>
                            <td class="font-mono font-semibold">{{ $l->chart_code }}</td>
                            <td class="wrap">{{ $chartNames[$l->chart_code] ?? '—' }}@if ($l->note)<div class="text-xs text-muted">{{ $l->note }}</div>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
