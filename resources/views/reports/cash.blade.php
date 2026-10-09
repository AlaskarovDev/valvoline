@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['direction']"/>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi label="Hesablanmış kassa qalığı (son)" :value="Fmt::money($kpi['balance'])" :big="true"/>
        <x-kpi label="Mədaxil (dövr)" :value="Fmt::money($kpi['income'])" tone="pos"/>
        <x-kpi label="Məxaric (dövr)" :value="Fmt::money($kpi['expense'])" tone="neg"/>
        <x-kpi label="Son sayım fərqi" :value="$kpi['last'] ? Fmt::money($kpi['last']->diff) : '—'"
               :meta="$kpi['last'] ? Fmt::date($kpi['last']->date).' · '.$kpi['mismatch'].' uyğunsuz nöqtə' : 'sayım nöqtəsi yoxdur'"
               :tone="$kpi['last'] && abs($kpi['last']->diff) >= 1 ? 'neg' : 'pos'"/>
    </div>

    <x-chart title="Kassa qalığı" sub="Hesablanmış ay sonu qalığı və sayım nöqtələri" :spec="$chart" :height="300"/>
    <x-table :table="$checkpointTable" :rows="$checkpointRows"/>
    <x-table :table="$listTable" :rows="$listRows"/>
</x-layouts.app>
