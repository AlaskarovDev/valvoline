@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['manager', 'customer']"/>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi label="Cəmi ödəniş" :value="Fmt::money($totals->total)" :meta="Fmt::number($totals->cnt).' əməliyyat'"/>
        <x-kpi label="Nağd" :value="Fmt::money($totals->cash)" :meta="$totals->total > 0 ? Fmt::pct($totals->cash / $totals->total).' payı' : null"/>
        <x-kpi label="Bank" :value="Fmt::money($totals->bank)" :meta="$totals->total > 0 ? Fmt::pct($totals->bank / $totals->total).' payı' : null"/>
        <x-kpi label="Geri ödənişlər" :value="Fmt::money($totals->refunds)" :tone="$totals->refunds < 0 ? 'neg' : null"/>
    </div>

    <x-chart title="Aylıq nağd və bank" :sub="$filters->label()" :spec="$chart" :height="300"/>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <x-table :table="$receiverTable" :rows="$receiverRows"/>
        <x-table :table="$managerTable" :rows="$managerRows"/>
    </div>
    <x-table :table="$listTable" :rows="$listRows"/>
</x-layouts.app>
