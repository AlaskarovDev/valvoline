@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-kpi label="Gözləmədə sətirlər" :value="Fmt::number($kpi['pending'])" :tone="$kpi['pending'] ? 'warn' : 'pos'"/>
        <x-kpi label="Uyğunlaşdırılmamış adlar" :value="Fmt::number($kpi['unmatched'])" :tone="$kpi['unmatched'] ? 'warn' : 'pos'" :href="route('aliases.index')" meta="Uyğunlaşdır →"/>
        <x-kpi label="Təxmini mayalı mallar" :value="Fmt::number($kpi['estimated'])" :tone="$kpi['estimated'] ? 'warn' : 'pos'"/>
        <x-kpi label="Mənfi qalığa düşən mallar" :value="Fmt::number($kpi['negative'])" :tone="$kpi['negative'] ? 'neg' : 'pos'"/>
        <x-kpi label="Ehtiyat mayası ilə satış sətri" :value="Fmt::number($kpi['fallback'])" meta="qrupun son mayası / ilk alış mayası"/>
    </div>

    <x-table :table="$pendingTable" :rows="$pendingRows"/>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <x-table :table="$estimatedTable" :rows="$estimatedRows"/>
        <x-table :table="$negativeTable" :rows="$negativeRows"/>
    </div>
    <x-table :table="$mismatchTable" :rows="$mismatchRows"/>
</x-layouts.app>
