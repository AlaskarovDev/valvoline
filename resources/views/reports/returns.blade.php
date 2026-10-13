@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters"/>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Müştəri qaytarmaları')" :value="Fmt::money($totals->returns)" tone="neg"/>
        <x-kpi :label="__('Satış (eyni dövr)')" :value="Fmt::money($totals->sales)"/>
        <x-kpi :label="__('Qaytarma faizi')" :value="Fmt::pct($totals->return_rate)" :meta="__('qaytarma / satış')"/>
        <x-kpi :label="__('Qaytarılan malın mayası')" :value="Fmt::money($totals->return_cogs)" :meta="__(':n ədəd', ['n' => Fmt::qty($totals->return_qty)])"/>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <x-chart class="xl:col-span-2" :title="__('Aylıq trend')" :sub="__('Qaytarma məbləği və satışa nisbəti')" :spec="$trend" :height="320"/>
        <x-chart :title="__('Səbəb üzrə bölgü')" :sub="__('Qaytarma məbləği')" :spec="$reasonChart" :height="320"/>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <x-table :table="$productTable" :rows="$productRows"/>
        <x-table :table="$customerTable" :rows="$customerRows"/>
    </div>
    <x-table :table="$listTable" :rows="$listRows"/>
</x-layouts.app>
