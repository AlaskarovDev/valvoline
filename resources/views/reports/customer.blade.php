@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('customers.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Müştərilər') }}</a>
        <span class="badge">Kod {{ $customer->code }}</span>
        @if ($customer->voen)<span class="badge">{{ __('VÖEN') }} {{ $customer->voen }}</span>@endif
        @if ($customer->manager)<span class="badge">Menecer: {{ $customer->manager->name }}</span>@endif
        @if ($customer->type === 'internal')<span class="badge badge-warn">{{ __('Daxili (müştəri deyil)') }}</span>@endif
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-kpi :label="__('Balans')" :value="Fmt::money($balance?->balance ?? 0)" :tone="($balance?->balance ?? 0) > 0.005 ? 'warn' : 'pos'"
               :meta="($balance?->balance ?? 0) > 0.005 ? __('müştəri borcludur') : (($balance?->balance ?? 0) < -0.005 ? __('avans / artıq ödəniş') : __('hesablaşma bağlıdır'))" :big="true"/>
        <x-kpi :label="__('Ümumi dövriyyə')" :value="Fmt::money($balance?->sales_total ?? 0)" :meta="__(':n sifariş', ['n' => Fmt::number($balance?->order_count ?? 0)])"/>
        <x-kpi :label="__('Ödənişlər')" :value="Fmt::money($balance?->payments_total ?? 0)" :meta="__('son').': '.Fmt::date($balance?->last_payment_date)"/>
        <x-kpi :label="__('Qaytarmalar')" :value="Fmt::money($balance?->returns_total ?? 0)"/>
        <x-kpi :label="__('Alış dövrü')" :value="Fmt::date($balance?->last_sale_date)" :meta="__('ilk').': '.Fmt::date($balance?->first_sale_date)"/>
    </div>

    @if (($balance?->balance ?? 0) > 0.005)
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Borc yaşı (FIFO)') }}</h2><span class="card-sub">{{ __(':date tarixinə', ['date' => Fmt::date($balance->as_of)]) }}</span></div>
            <div class="grid grid-cols-2 gap-px bg-line sm:grid-cols-4">
                @foreach (['aging_0_30' => __('0–30 gün'), 'aging_31_60' => __('31–60 gün'), 'aging_61_90' => __('61–90 gün'), 'aging_90_plus' => __('90+ gün')] as $key => $label)
                    <div class="bg-surface p-4">
                        <div class="kpi-label">{{ $label }}</div>
                        <div @class(['mt-1 text-lg font-semibold num text-left', 'neg' => $key === 'aging_90_plus' && $balance->$key > 0])>{{ Fmt::money($balance->$key) }}</div>
                    </div>
                @endforeach
            </div>
            @php $minPayment = (float) $balance->aging_31_60 + (float) $balance->aging_61_90 + (float) $balance->aging_90_plus; @endphp
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-4 py-3 text-sm" style="background: var({{ $minPayment > 0.005 ? '--warning-soft' : '--positive-soft' }})">
                <span>{{ __('0–30 gün qrupuna düşmək üçün minimal ödəniş') }}</span>
                <span class="text-lg font-bold tabular-nums {{ $minPayment > 0.005 ? 'warn' : 'pos' }}">{{ Fmt::money($minPayment) }}</span>
            </div>
        </section>
    @endif

    <x-chart :title="__('Aylıq alış və ödəniş')" :spec="$monthlyChart" :height="280"/>

    <x-filters :filters="$filters" :show="[]"/>
    <x-table :table="$statementTable" :rows="$statementRows"/>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <x-table class="xl:col-span-2" :table="$productTable" :rows="$productRows"/>
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Ad yazılışları (alias)') }}</h2><span class="card-sub">{{ $customer->aliases->count() }}</span></div>
            <ul class="divide-y divide-line text-sm">
                <li class="px-4 py-2.5 font-medium">{{ $customer->name }} <span class="badge ml-1">{{ __('əsas ad') }}</span></li>
                @foreach ($customer->aliases as $alias)
                    <li class="flex items-center justify-between gap-2 px-4 py-2.5">
                        <span>{{ $alias->original_name }}</span>
                        <span class="badge">{{ $alias->method ?? __('fayl') }}</span>
                    </li>
                @endforeach
                @if ($customer->other_names)
                    @foreach (preg_split('/\s*[|;]\s*/u', $customer->other_names) as $other)
                        <li class="px-4 py-2.5 text-muted">{{ $other }}</li>
                    @endforeach
                @endif
            </ul>
        </section>
    </div>
</x-layouts.app>
