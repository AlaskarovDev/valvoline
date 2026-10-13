@php
    use App\Support\Fmt;
    $natureLabels = ['A' => __('Aktiv'), 'P' => __('Passiv'), 'K' => __('Kontr-aktiv'), 'G' => __('Gəlir'), 'X' => __('Xərc'), 'N' => __('Nəticə'), 'B' => __('Balansdankənar')];
    $natureClass = ['A' => 'badge-primary', 'P' => 'badge-warn', 'K' => '', 'G' => 'badge-pos', 'X' => 'badge-neg', 'N' => '', 'B' => ''];
@endphp
<x-layouts.app :title="$title">
    <div class="card px-4 py-3 text-sm text-muted">
        {{ __('Azərbaycan Respublikasında kommersiya təşkilatları üçün Mühasibat uçotu hesablarının Hesablar Planı (bölmə → maddə → hesab). Adlar redaktə oluna, yeni hesab əlavə edilə bilər — mühasibinizlə tutuşdurun.') }}
        @if ($year)
            {{ __('«Qalıq» sütunu :year balans faylındakı köhnə hesabların yeni plana uyğunluğu ilə hesablanır.', ['year' => $year]) }}
        @endif
    </div>

    <form method="GET" class="card flex flex-wrap items-center gap-2 p-3">
        <div class="-mx-1 flex w-[calc(100%+0.5rem)] gap-1.5 overflow-x-auto px-1 pb-0.5 sm:w-auto sm:flex-wrap sm:overflow-visible">
            <a href="{{ route('accounting.chart', array_filter(['q' => $q])) }}" @class(['chip shrink-0', 'active' => $section === ''])>{{ __('Hamısı') }}</a>
            @foreach ($sections as $no => $name)
                <a href="{{ route('accounting.chart', array_filter(['section' => (string) $no, 'q' => $q], fn ($v) => $v !== '')) }}" @class(['chip shrink-0', 'active' => $section === (string) $no])>{{ $no }} · {{ $name }}</a>
            @endforeach
        </div>
        <input type="hidden" name="section" value="{{ $section }}">
        <label class="sr-only" for="chart-q">{{ __('Axtarış') }}</label>
        <input id="chart-q" name="q" value="{{ $q }}" placeholder="{{ __('Hesab nömrəsi və ya adı…') }}" class="input h-[34px] min-w-0 flex-1 text-xs sm:h-[30px] sm:w-56 sm:flex-none">
        <button class="btn btn-sm btn-primary">{{ __('Tətbiq et') }}</button>
    </form>

    @forelse ($accounts as $sec => $list)
        <section class="card overflow-hidden">
            <div class="card-head">
                <h2 class="card-title">{{ __('Bölmə') }} {{ $sec }}. {{ $sections[$sec] ?? '' }}</h2>
                <span class="card-sub">{{ __(':n hesab', ['n' => $list->count()]) }}</span>
            </div>
            <ul class="divide-y divide-line">
                @foreach ($list->groupBy('group_code') as $group => $items)
                    <li class="bg-surface-2 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $group }} · {{ $groups[$group] ?? '' }}</li>
                    @foreach ($items as $a)
                        <li class="grid grid-cols-[56px_minmax(0,1fr)] items-center gap-x-3 gap-y-1 px-4 py-2 text-sm md:grid-cols-[56px_minmax(0,1fr)_110px_140px_auto]" x-data="{ edit: false }">
                            <span class="font-mono font-semibold {{ $a->is_active ? '' : 'text-faint line-through' }}">{{ $a->code }}</span>
                            <span class="min-w-0" x-show="!edit">{{ $a->name }} @if ($a->user_edited)<span class="badge">{{ __('əl ilə') }}</span>@endif</span>
                            <form x-show="edit" x-cloak method="POST" action="{{ route('accounting.chart.update', $a->id) }}" class="col-span-full flex flex-wrap items-center gap-2 md:col-span-3">
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
                            <span class="col-start-2 text-right tabular-nums md:col-start-auto {{ isset($balances[$a->code]) && $balances[$a->code] < 0 ? 'neg' : '' }}" x-show="!edit">
                                {{ isset($balances[$a->code]) ? Fmt::money($balances[$a->code]) : '' }}
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
