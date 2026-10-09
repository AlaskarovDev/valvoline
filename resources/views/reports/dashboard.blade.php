@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['manager', 'category', 'series']"/>

    <div class="text-xs text-muted">{{ $filters->label() }} · müqayisə: keçən ilin eyni dövrü</div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi label="Xalis satış" :value="Fmt::money($kpi['net_sales'][0])" :delta="$kpi['net_sales'][1]" :href="route('sales.index', $filters->query())"/>
        <x-kpi label="Brüt mənfəət" :value="Fmt::money($kpi['gross_profit'][0])" :delta="$kpi['gross_profit'][1]" :tone="$kpi['gross_profit'][0] < 0 ? 'neg' : null" :href="route('margin.index', $filters->query())"/>
        <x-kpi label="Marja" :value="Fmt::pct($kpi['margin'][0])"
               :meta="'keçən il: '.Fmt::pct($kpi['margin'][1])"/>
        <x-kpi label="Sifariş sayı" :value="Fmt::number($kpi['orders'][0])" :delta="$kpi['orders'][1]" :href="route('orders.index', $filters->query())"/>
        @if ($isAdmin)
            <x-kpi label="Əməliyyat xərcləri ({{ $filters->direction ?? 'hamısı' }})" :value="Fmt::money($kpi['expenses'][0])" :delta="$kpi['expenses'][1]" :inverse="true" :href="route('expenses.index', $filters->query())"/>
            <x-kpi label="Xalis mənfəət" :value="Fmt::money($kpi['net_profit'][0])" :delta="$kpi['net_profit'][1]" :tone="$kpi['net_profit'][0] < 0 ? 'neg' : 'pos'" :href="route('pnl.index', $filters->query())"/>
        @endif
        <x-kpi label="Müştəri borcu (cəmi)" :value="Fmt::money($kpi['debt'][0])" :meta="Fmt::number($kpi['debt'][1]).' borclu müştəri'" tone="warn" :href="route('debts.index')"/>
        <x-kpi label="Anbar dəyəri (maya ilə)" :value="Fmt::money($kpi['stock'][0])" :meta="Fmt::number($kpi['stock'][1]).' ədəd'" :href="route('stock.index')"/>
    </div>

    <x-chart title="Son 24 ay: satış və mənfəət" sub="Sütunlar — xalis satış və brüt mənfəət, xətt — marja %. Aya klikləyin → sifarişlər" :spec="$trend" :height="330"/>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <x-chart title="Kateqoriya payı" sub="Xalis satış üzrə" :spec="$categories" :height="320"/>
        <x-chart class="xl:col-span-2" title="Ən çox satılan 10 mal" sub="Xalis satış" :spec="$topProducts" :height="320"/>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <x-chart title="Ən böyük 10 borclu" sub="Cari balans" :spec="$topDebtors" :height="320"/>
        <x-chart title="Menecerlər üzrə satış" sub="Xalis satış və brüt mənfəət" :spec="$managers" :height="320"/>
    </div>
</x-layouts.app>
