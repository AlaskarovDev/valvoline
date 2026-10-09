@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters"/>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi label="Müştəri qaytarmaları" :value="Fmt::money($totals->returns)" tone="neg"/>
        <x-kpi label="Satış (eyni dövr)" :value="Fmt::money($totals->sales)"/>
        <x-kpi label="Qaytarma faizi" :value="Fmt::pct($totals->return_rate)" :meta="'qaytarma / satış'"/>
        <x-kpi label="Qaytarılan malın mayası" :value="Fmt::money($totals->return_cogs)" :meta="Fmt::qty($totals->return_qty).' ədəd'"/>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <x-chart class="xl:col-span-2" title="Aylıq trend" sub="Qaytarma məbləği və satışa nisbəti" :spec="$trend" :height="320"/>
        <x-chart title="Səbəb üzrə bölgü" sub="Qaytarma məbləği" :spec="$reasonChart" :height="320"/>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <x-table :table="$productTable" :rows="$productRows"/>
        <x-table :table="$customerTable" :rows="$customerRows"/>
    </div>
    <x-table :table="$listTable" :rows="$listRows"/>
</x-layouts.app>
