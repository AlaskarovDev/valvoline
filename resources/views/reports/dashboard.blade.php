@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['manager', 'category', 'series']"/>

    <div class="text-xs text-muted">{{ $filters->label() }} · {{ __('müqayisə: keçən ilin eyni dövrü') }}</div>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-kpi :label="__('Xalis satış')" :value="Fmt::money($kpi['net_sales'][0])" :delta="$kpi['net_sales'][1]" :href="route('sales.index', $filters->query())"/>
        <x-kpi :label="__('Brüt mənfəət')" :value="Fmt::money($kpi['gross_profit'][0])" :delta="$kpi['gross_profit'][1]" :tone="$kpi['gross_profit'][0] < 0 ? 'neg' : null" :href="route('margin.index', $filters->query())"/>
        <x-kpi :label="__('Marja')" :value="Fmt::pct($kpi['margin'][0])"
               :meta="__('keçən il').': '.Fmt::pct($kpi['margin'][1])"/>
        <x-kpi :label="__('Sifariş sayı')" :value="Fmt::number($kpi['orders'][0])" :delta="$kpi['orders'][1]" :href="route('orders.index', $filters->query())"/>
        @if ($isAdmin)
            <x-kpi :label="__('Əməliyyat xərcləri').' ('.($filters->direction && $filters->direction !== 'all' ? $filters->direction : __('hamısı')).')'" :value="Fmt::money($kpi['expenses'][0])" :delta="$kpi['expenses'][1]" :inverse="true" :href="route('expenses.index', $filters->query())"/>
            <x-kpi :label="__('Xalis mənfəət')" :value="Fmt::money($kpi['net_profit'][0])" :delta="$kpi['net_profit'][1]" :tone="$kpi['net_profit'][0] < 0 ? 'neg' : 'pos'" :href="route('pnl.index', $filters->query())"/>
        @endif
        <x-kpi :label="__('Müştəri borcu (cəmi)')" :value="Fmt::money($kpi['debt'][0])" :meta="__(':n borclu müştəri', ['n' => Fmt::number($kpi['debt'][1])])" tone="warn" :href="route('debts.index')"/>
        <x-kpi :label="__('Anbar dəyəri (maya ilə)')" :value="Fmt::money($kpi['stock'][0])" :meta="__(':n ədəd', ['n' => Fmt::number($kpi['stock'][1])])" :href="route('stock.index')"/>
    </div>

    @if ($official)
        @php $o = $official['summary']; @endphp
        <section class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ __('Rəsmi göstəricilər (mühasibat)') }}</h2>
                    <div class="card-sub">{{ $o ? $filters->label() : __('Bu dövr üçün mühasibat məlumatı yoxdur — son məlumat: :period', ['period' => Fmt::period($official['last_period'])]) }}</div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-px bg-line xl:grid-cols-4">
                <div class="bg-surface p-4">
                    <div class="kpi-label">{{ __('Pul vəsaitləri (bank + kassa)') }}</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums">{{ Fmt::money($official['bank_balance'] + $official['cash_balance']) }}</div>
                    <dl class="mt-1 space-y-0.5 text-xs">
                        <div class="flex justify-between gap-2"><dt><a href="{{ route('bank.index') }}" class="text-muted hover:text-primary">{{ __('Bank') }}</a> <span class="text-faint">({{ Fmt::period($official['bank_period']) }})</span></dt><dd class="tabular-nums">{{ Fmt::money($official['bank_balance'], 0) }}</dd></div>
                        <div class="flex justify-between gap-2"><dt><a href="{{ route('cash.index') }}" class="text-muted hover:text-primary">{{ __('Kassa') }}</a> <span class="text-faint">({{ $official['cash_counted'] ? __('sayım :date', ['date' => Fmt::date($official['cash_date'])]) : Fmt::date($official['cash_date']) }})</span></dt><dd class="tabular-nums">{{ Fmt::money($official['cash_balance'], 0) }}</dd></div>
                    </dl>
                </div>
                <a href="{{ route('accounting.reconcile') }}" class="block bg-surface p-4 hover:bg-surface-2">
                    <div class="kpi-label">{{ __('Rəsmi daxilolmalar (müştərilər)') }}</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums pos">{{ Fmt::money($o['customer'] ?? 0) }}</div>
                    <div class="text-xs text-muted">{{ __('sistemdə var') }}: {{ Fmt::money($o['included'] ?? 0, 0) }}@if (($o['pending'] ?? 0) > 0) · <span class="warn">{{ __('yoxlanılmalı') }}: {{ Fmt::money($o['pending'], 0) }}</span>@endif</div>
                </a>
                <a href="{{ route('pnl.index', $filters->query(['resmi' => 'daxil'])) }}" class="block bg-surface p-4 hover:bg-surface-2">
                    <div class="kpi-label">{{ __('Rəsmi xərclər (bank)') }}</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums neg">{{ Fmt::money($official['bank_expenses']) }}</div>
                    <div class="text-xs text-muted">{{ __('P&L-də «Daxil» seçiləndə xərclərə düşür') }}</div>
                </a>
                <a href="{{ route('pnl.index', $filters->query()) }}" class="block bg-surface p-4 hover:bg-surface-2">
                    <div class="kpi-label">{{ __('Təsisçidən / re-eksport') }}</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums">{{ Fmt::money($o['founder'] ?? 0) }}</div>
                    <div class="text-xs text-muted">{{ __('təsisçidən — gəlir deyil') }} · {{ __('re-eksport nəticəsi') }}: {{ Fmt::money($official['export'], 0) }}</div>
                </a>
            </div>
        </section>
    @endif

    @php [$pay, $prevPay] = $payments; @endphp
    <section class="card overflow-hidden">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ __('Müştəri ödənişləri') }}</h2>
                <div class="card-sub">{{ $filters->label() }} · {{ __(':n ödəniş', ['n' => Fmt::number($pay->count)]) }}</div>
            </div>
            <a href="{{ route('payments.index', $filters->query()) }}" class="btn btn-sm">{{ __('Ödənişlər') }} →</a>
        </div>
        <div class="grid grid-cols-1 gap-px bg-line sm:grid-cols-3">
            @foreach ([[__('Bank (köçürmə)'), $pay->bank, $prevPay->bank], [__('Nağd'), $pay->cash, $prevPay->cash], [__('Cəmi'), $pay->total, $prevPay->total]] as [$label, $value, $previousValue])
                @php $delta = Fmt::delta($value, $previousValue); @endphp
                <div class="bg-surface p-4">
                    <div class="kpi-label">{{ $label }}</div>
                    <div @class(['mt-1 tabular-nums font-semibold', 'text-2xl' => $loop->last, 'text-xl' => ! $loop->last])>{{ Fmt::money($value) }}</div>
                    <div class="text-xs text-muted">
                        @if ($pay->total > 0 && ! $loop->last){{ Fmt::pct($value / $pay->total) }} · @endif
                        @if ($delta !== null)<span class="{{ $delta < 0 ? 'neg' : 'pos' }}">{{ $delta < 0 ? '▼' : '▲' }} {{ Fmt::pct(abs($delta)) }}</span> {{ __('keçən il') }}@endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <x-chart :title="__('Son 24 ay: satış və mənfəət')" :sub="__('Sütunlar — xalis satış və brüt mənfəət, xətt — marja %. Aya klikləyin → sifarişlər')" :spec="$trend" :height="330"/>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <x-chart :title="__('Kateqoriya payı')" :sub="__('Xalis satış üzrə')" :spec="$categories" :height="320"/>
        <x-chart class="xl:col-span-2" :title="__('Ən çox satılan 10 mal')" :sub="__('Xalis satış')" :spec="$topProducts" :height="320"/>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <x-chart :title="__('Ən böyük 10 borclu')" :sub="__('Cari balans')" :spec="$topDebtors" :height="320"/>
        <x-chart :title="__('Menecerlər üzrə satış')" :sub="__('Xalis satış və brüt mənfəət')" :spec="$managers" :height="320"/>
    </div>
</x-layouts.app>
