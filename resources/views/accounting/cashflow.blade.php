@php
    use App\Services\FlowClassifier;
    use App\Support\Fmt;
    $sectionLabels = ['income' => __('Mədaxil'), 'payment' => __('Ödəniş'), 'other' => __('Digər')];
    $typeClass = ['expense' => 'badge-neg', 'cogs' => 'badge-primary', 'tax' => 'badge-warn', 'financing' => 'badge-warn', 'capex' => '', 'refund' => '', 'revenue' => 'badge-pos', 'fx' => '', 'uncertain' => 'badge-warn'];
    $paymentTotal = $byType->filter(fn ($v, $k) => str_starts_with($k, 'payment|'))->sum();
    $expenseTotal = $byType['payment|expense'] ?? 0;
@endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-xs text-muted">{{ __('Bank / kassa hərəkətləri (mühasibat faylı). Yalnız «Əməliyyat xərci» növü P&L-ə «Bank xərcləri» kimi düşür.') }}</span>
        <div class="ml-auto">@include('accounting._year')</div>
    </div>

    @if ($year)
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-kpi :label="__('Dövrün əvvəlinə qalıq')" :value="Fmt::money($opening)"/>
            <x-kpi :label="__('Mədaxil')" :value="Fmt::money($byType->filter(fn ($v, $k) => str_starts_with($k, 'income|'))->sum())" tone="pos"
                   :meta="__('o cümlədən təsisçidən: :sum', ['sum' => Fmt::money($byType['income|financing'] ?? 0)])"/>
            <x-kpi :label="__('Ödənişlər')" :value="Fmt::money($paymentTotal)" tone="neg"
                   :meta="__('əməliyyat xərci: :sum', ['sum' => Fmt::money($expenseTotal)])"/>
            <x-kpi :label="__('Dövrün sonuna qalıq')" :value="Fmt::money($closing)"/>
        </div>

        <section class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ __('Ödənişlərin təhlili — hamısı xərc deyil') }}</h2>
                    <div class="card-sub">{{ __('Növə klik — siyahını süz. ⚠ — avtomatik təsnifat ehtimaldır, yoxlayın.') }}</div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-px bg-line md:grid-cols-3 xl:grid-cols-6">
                @foreach (['expense', 'cogs', 'tax', 'capex', 'refund', 'uncertain'] as $type)
                    @php $sum = $byType['payment|'.$type] ?? 0; @endphp
                    <a href="{{ route('accounting.cashflow', ['year' => $year, 'type' => $typeFilter === $type ? null : $type]) }}" @class(['block bg-surface p-4 hover:bg-surface-2', 'ring-2 ring-inset ring-primary' => $typeFilter === $type])>
                        <div class="kpi-label">{{ $typeLabels[$type] }}</div>
                        <div class="mt-1 text-lg font-semibold tabular-nums {{ $type === 'expense' ? 'neg' : ($type === 'uncertain' && $sum > 0 ? 'warn' : '') }}">{{ Fmt::money($sum) }}</div>
                        <div class="text-xs text-muted">{{ $paymentTotal > 0 ? Fmt::pct($sum / $paymentTotal) : '' }}
                            @if ($type === 'expense') · {{ __('P&L-ə düşür') }} @else · {{ __('P&L-ə düşmür') }} @endif</div>
                    </a>
                @endforeach
            </div>
            @if ($uncertainTotal > 0)
                <div class="border-t border-line px-4 py-2.5 text-sm warn">⚠ {{ __('Təsnif olunmamış ödənişlər: :sum — növünü seçənə qədər P&L-ə düşmür.', ['sum' => Fmt::money($uncertainTotal)]) }}</div>
            @endif
        </section>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <x-chart class="xl:col-span-2" :title="__('Aylıq ödənişlər növ üzrə')" :spec="$paymentChart" :height="320"/>
            <section class="card overflow-hidden">
                <div class="card-head"><h2 class="card-title">{{ __('Əməliyyat xərcləri qrup üzrə') }}</h2><span class="card-sub">{{ Fmt::money($expenseTotal) }}</span></div>
                <ul class="divide-y divide-line text-sm">
                    @foreach ($expenseByGroup as $group => $sum)
                        <li class="flex items-center justify-between gap-3 px-4 py-2">
                            <span>{{ FlowClassifier::groupLabel($group) }}</span>
                            <span class="tabular-nums font-medium">{{ Fmt::money($sum) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        <section class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ __('Kontragentlər və təsnifat') }}</h2>
                    <div class="card-sub">{{ __(':n sətir', ['n' => $rows->count()]) }} · {{ __('Növü dəyişəndə P&L dərhal yenilənir; seçim növbəti illərin faylına da tətbiq olunur.') }}</div>
                </div>
                @if ($typeFilter)<a href="{{ route('accounting.cashflow', ['year' => $year]) }}" class="chip active">{{ $typeLabels[$typeFilter] ?? $typeFilter }} ✕</a>@endif
            </div>
            <nav class="tabs px-2" aria-label="{{ __('Bölmə') }}">
                @foreach (['payment' => __('Ödənişlər'), 'income' => __('Mədaxil'), 'other' => __('Digər'), 'all' => __('Hamısı')] as $key => $label)
                    <a href="{{ route('accounting.cashflow', array_filter(['year' => $year, 'section' => $key, 'type' => $typeFilter])) }}" @class(['tab', 'active' => $sectionFilter === $key])>
                        {{ $label }} @if ($key !== 'all')<span class="text-faint">({{ $sectionCounts[$key] ?? 0 }})</span>@endif
                    </a>
                @endforeach
            </nav>
            <div class="tbl-wrap max-h-[75vh]">
                <table class="tbl">
                    <thead>
                    <tr>
                        <th class="sticky left-0 z-[2] min-w-64 bg-surface-2">{{ __('Kontragent') }}</th>
                        <th>{{ __('Bölmə') }}</th>
                        <th class="num">{{ __('Cəmi') }}</th>
                        <th>{{ __('Növ · xərc qrupu') }}</th>
                        @foreach ($months as $m)<th class="num">{{ Fmt::period($m) }}</th>@endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td class="sticky left-0 z-[1] max-w-80 whitespace-normal bg-surface" title="{{ $r->note }}">
                                <div class="font-medium">{{ $r->counterparty }}</div>
                                @if ($r->confidence === 'low')
                                    <div class="text-xs text-muted"><span class="warn">⚠</span> {{ $r->note }}</div>
                                @elseif ($r->confidence === 'user')
                                    <div class="text-xs text-muted"><span class="badge badge-primary">{{ __('əl ilə') }}</span> {{ $r->note }}</div>
                                @endif
                            </td>
                            <td><span class="badge">{{ $sectionLabels[$r->section] ?? $r->section }}</span></td>
                            <td class="num font-semibold {{ $r->section === 'payment' ? 'neg' : '' }}">{{ Fmt::money($r->total) }}</td>
                            <td>
                                @if ($r->section === 'other')
                                    <span class="badge {{ $typeClass[$r->type] ?? '' }}">{{ $typeLabels[$r->type] ?? $r->type }}</span>
                                @else
                                    <form method="POST" action="{{ route('accounting.classify') }}" class="flex flex-wrap items-center gap-1.5">
                                        @csrf
                                        <input type="hidden" name="normalized" value="{{ $r->normalized }}">
                                        <input type="hidden" name="counterparty" value="{{ $r->counterparty }}">
                                        <label class="sr-only" for="t-{{ $loop->index }}">{{ __('Növ') }}</label>
                                        <select id="t-{{ $loop->index }}" name="type" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
                                            @foreach ($typeLabels as $value => $label)
                                                <option value="{{ $value }}" @selected($r->type === $value)>{{ $label }}</option>
                                            @endforeach
                                            @if ($r->confidence === 'user')<option value="reset">↺ {{ __('Avtomatik təsnifata qaytar') }}</option>@endif
                                        </select>
                                        @if ($r->type === 'expense')
                                            <label class="sr-only" for="g-{{ $loop->index }}">{{ __('Xərc qrupu') }}</label>
                                            <select id="g-{{ $loop->index }}" name="expense_group" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
                                                @foreach ($groups as $g)<option value="{{ $g }}" @selected($r->group === $g)>{{ FlowClassifier::groupLabel($g) }}</option>@endforeach
                                            </select>
                                        @endif
                                    </form>
                                @endif
                            </td>
                            @foreach ($months as $m)
                                <td class="num {{ empty($r->months[$m]) ? 'text-faint' : '' }}">{{ empty($r->months[$m]) ? '—' : Fmt::money($r->months[$m], 0, false) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($balances->isNotEmpty())
            <section class="card overflow-hidden">
                <div class="card-head"><h2 class="card-title">{{ __('Ay sonuna pul vəsaitləri (hesablar üzrə)') }}</h2></div>
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th class="sticky left-0 z-[2] bg-surface-2">{{ __('Hesab') }}</th>@foreach ($months as $m)<th class="num">{{ Fmt::period($m) }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach ($balances as $account => $list)
                            @php $byPeriod = $list->keyBy('period'); @endphp
                            <tr>
                                <td class="sticky left-0 z-[1] bg-surface">{{ $account }}</td>
                                @foreach ($months as $m)<td class="num">{{ isset($byPeriod[$m]) ? Fmt::money($byPeriod[$m]->amount, 0, false) : '—' }}</td>@endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
</x-layouts.app>
