@php use App\Support\Fmt; $pct = fn ($v) => $v === null ? '—' : Fmt::number($v, 1).' %'; @endphp
<x-layouts.app :title="$title">
    <form method="GET" class="flex flex-wrap items-end gap-2">
        <div><label class="label" for="pf-period">{{ __('Ay') }}</label><input id="pf-period" type="month" name="period" value="{{ $period }}" class="input" onchange="this.form.submit()"></div>
        <a href="{{ route('reps.performance', ['period' => \Illuminate\Support\Carbon::parse($period.'-01')->subMonth()->format('Y-m')]) }}" class="btn btn-sm">‹ {{ __('Əvvəlki ay') }}</a>
        <a href="{{ route('reps.performance', ['period' => \Illuminate\Support\Carbon::parse($period.'-01')->addMonth()->format('Y-m')]) }}" class="btn btn-sm">{{ __('Növbəti ay') }} ›</a>
    </form>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Hədəf')" :value="Fmt::money($totals['sales_target'])"/>
        <x-kpi :label="__('Fakt satış')" :value="Fmt::money($totals['sales'])" :meta="$totals['sales_target'] ? $pct(round($totals['sales'] / $totals['sales_target'] * 100, 1)) : null"/>
        <x-kpi :label="__('Ay sonuna proqnoz')" :value="Fmt::money($totals['forecast'])" :meta="$totals['sales_target'] ? $pct(round($totals['forecast'] / $totals['sales_target'] * 100, 1)) : null"/>
        <x-kpi :label="__('Bonus fondu')" :value="Fmt::money($totals['bonus'])" :meta="__('inkasso').': '.Fmt::money($totals['collections'])"/>
    </div>

    <form method="POST" action="{{ route('reps.targets') }}" class="card overflow-hidden">
        @csrf
        <input type="hidden" name="period" value="{{ $period }}">
        <div class="card-head"><div><h2 class="card-title">{{ __('Nümayəndələr') }}</h2>
            <div class="card-sub">{{ __('Fakt satış = satış − qaytarma. Proqnoz bu günə qədərki tempə görədir. Hədəf boşdursa, nümayəndə kartındakı aylıq plan götürülür.') }}</div></div></div>
        <div class="tbl-wrap relative">
            <table class="tbl">
                <thead><tr><th>{{ __('Nümayəndə') }}</th><th class="num">{{ __('Satış hədəfi') }}</th><th class="num">{{ __('Fakt satış') }}</th><th class="num">{{ __('İcra') }}</th>
                    <th class="num">{{ __('Proqnoz') }}</th><th class="num">{{ __('İnkasso hədəfi') }}</th><th class="num">{{ __('İnkasso') }}</th><th class="num">{{ __('İcra') }}</th>
                    <th class="num">{{ __('Brüt mənfəət') }}</th><th class="num">{{ __('Bonus %') }}</th><th class="num">{{ __('Bonus') }}</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td>{{ $r->name }}@if ($r->code) <span class="text-xs text-muted">{{ $r->code }}</span>@endif</td>
                            <td class="num">@if ($canEdit)<label class="sr-only" for="t-s-{{ $r->id }}">{{ __('Satış hədəfi') }}</label><input id="t-s-{{ $r->id }}" type="number" step="0.01" min="0" name="targets[{{ $r->id }}][sales]" value="{{ $r->sales_target }}" class="input w-28 text-right">@else{{ Fmt::money($r->sales_target) }}@endif</td>
                            <td class="num">{{ Fmt::money($r->sales) }}</td>
                            <td @class(['num font-semibold', 'pos' => ($r->sales_pct ?? 0) >= 100, 'warn' => $r->sales_pct !== null && $r->sales_pct < 100 && $r->sales_pct >= 80, 'neg' => $r->sales_pct !== null && $r->sales_pct < 80])>{{ $pct($r->sales_pct) }}</td>
                            <td class="num text-muted">{{ Fmt::money($r->forecast) }}@if ($r->forecast_pct !== null)<div class="text-xs">{{ $pct($r->forecast_pct) }}</div>@endif</td>
                            <td class="num">@if ($canEdit)<label class="sr-only" for="t-c-{{ $r->id }}">{{ __('İnkasso hədəfi') }}</label><input id="t-c-{{ $r->id }}" type="number" step="0.01" min="0" name="targets[{{ $r->id }}][collections]" value="{{ $r->collection_target }}" class="input w-28 text-right">@else{{ Fmt::money($r->collection_target) }}@endif</td>
                            <td class="num">{{ Fmt::money($r->collections) }}</td>
                            <td class="num">{{ $pct($r->collection_pct) }}</td>
                            <td class="num">{{ Fmt::money($r->profit) }}</td>
                            <td class="num">{{ Fmt::number($r->bonus_rate, 2) }}</td>
                            <td class="num font-semibold">{{ Fmt::money($r->bonus) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="py-6 text-center text-muted">{{ __('Nümayəndə yoxdur.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($canEdit && $rows->isNotEmpty())<div class="flex justify-end border-t border-line p-4"><button class="btn btn-primary">{{ __('Hədəfləri yadda saxla') }}</button></div>@endif
    </form>

    <section class="card">
        <div class="card-head"><div><h2 class="card-title">{{ __('Bonus qaydaları') }}</h2>
            <div class="card-sub">{{ __('İcra faizi pilləyə çatanda həmin pillənin faizi bonus bazasına tətbiq olunur (ən yüksək çatılan pillə).') }}</div></div></div>
        @if ($canEdit)
            <form method="POST" action="{{ route('reps.bonus-rules') }}" class="flex flex-col gap-3 p-4" x-data="{ tiers: @js($rules['tiers']) }">
                @csrf
                <div class="flex flex-wrap gap-3">
                    <div><label class="label" for="b-measure">{{ __('İcra nəyə görə ölçülür') }}</label>
                        <select id="b-measure" name="measure" class="select"><option value="sales" @selected($rules['measure'] === 'sales')>{{ __('Satış hədəfi') }}</option><option value="collections" @selected($rules['measure'] === 'collections')>{{ __('İnkasso hədəfi') }}</option></select></div>
                    <div><label class="label" for="b-base">{{ __('Bonus bazası') }}</label>
                        <select id="b-base" name="base" class="select">@foreach ($bases as $k => $l)<option value="{{ $k }}" @selected($rules['base'] === $k)>{{ $l }}</option>@endforeach</select></div>
                </div>
                <template x-for="(t, i) in tiers" :key="i">
                    <div class="flex flex-wrap items-end gap-2 text-sm">
                        <div><label class="label" :for="'b-from-' + i">{{ __('İcra ≥ %') }}</label><input :id="'b-from-' + i" type="number" step="0.1" min="0" :name="`tiers[${i}][from]`" x-model="t.from" class="input w-24"></div>
                        <div><label class="label" :for="'b-rate-' + i">{{ __('Bonus %') }}</label><input :id="'b-rate-' + i" type="number" step="0.01" min="0" :name="`tiers[${i}][rate]`" x-model="t.rate" class="input w-24"></div>
                        <button type="button" class="btn btn-sm h-[34px]" @click="tiers.splice(i, 1)" aria-label="{{ __('Pilləni sil') }}"><x-icon name="trash" size="14"/></button>
                    </div>
                </template>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm" @click="tiers.push({ from: '', rate: '' })"><x-icon name="plus" size="14"/> {{ __('Pillə') }}</button>
                    <button class="btn btn-sm btn-primary sm:ml-auto">{{ __('Qaydaları yadda saxla') }}</button>
                </div>
            </form>
        @else
            <ul class="p-4 text-sm">@foreach ($rules['tiers'] as $t)<li>{{ __('İcra ≥ :from % → bonus :rate %', ['from' => Fmt::number($t['from'], 1), 'rate' => Fmt::number($t['rate'], 2)]) }}</li>@endforeach</ul>
        @endif
    </section>
</x-layouts.app>
