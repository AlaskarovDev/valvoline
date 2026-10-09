@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['manager']" :dates="false" :own="['tab']">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <span class="text-xs text-muted">Balanslar {{ Fmt::date($summary->as_of) }} tarixinə · FIFO borc yaşı</span>
    </x-filters>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi class="sm:col-span-2" label="Mənə olan borclar (cəmi)" :value="Fmt::money($summary->debt)" tone="warn" :big="true"
               :meta="Fmt::number($summary->debtors).' borclu müştəri'"/>
        <x-kpi label="Avans / artıq ödənişlər" :value="Fmt::money($summary->advances)" tone="pos" :meta="Fmt::number($summary->advance_count).' müştəri'"/>
        <x-kpi label="Xalis debitor borc" :value="Fmt::money($summary->net)" :meta="'borclar − avanslar'"/>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <x-chart title="Borc yaşı" sub="Ödənişlər ən köhnə satışlara bağlanır (FIFO)" :spec="$aging" :height="280"/>
        <x-table class="xl:col-span-2" :table="$managerTable" :rows="$managerRows"/>
    </div>

    <nav class="tabs" aria-label="Siyahı">
        <a href="{{ route('debts.index', array_merge(request()->except(['tab', 'debts_page']), [])) }}" @class(['tab', 'active' => $tab === 'debtors'])>Borclular ({{ Fmt::number($summary->debtors) }})</a>
        <a href="{{ route('debts.index', array_merge(request()->except('debts_page'), ['tab' => 'advances'])) }}" @class(['tab', 'active' => $tab === 'advances'])>Avans / artıq ödəyənlər ({{ Fmt::number($summary->advance_count) }})</a>
    </nav>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
