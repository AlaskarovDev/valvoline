@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['manager']" :dates="false" :own="['tab']">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <span class="text-xs text-muted">{{ __('Balanslar :date tarixinə · FIFO borc yaşı', ['date' => Fmt::date($summary->as_of)]) }}</span>
    </x-filters>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-kpi class="col-span-2" :label="__('Mənə olan borclar (cəmi)')" :value="Fmt::money($summary->debt)" tone="warn" :big="true"
               :meta="__(':n borclu müştəri', ['n' => Fmt::number($summary->debtors)])"/>
        <x-kpi :label="__('Avans / artıq ödənişlər')" :value="Fmt::money($summary->advances)" tone="pos" :meta="__(':n müştəri', ['n' => Fmt::number($summary->advance_count)])"/>
        <x-kpi :label="__('Xalis debitor borc')" :value="Fmt::money($summary->net)" :meta="'borclar − avanslar'"/>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <x-chart :title="__('Borc yaşı')" :sub="__('Ödənişlər ən köhnə satışlara bağlanır (FIFO)')" :spec="$aging" :height="280"/>
        <x-table class="xl:col-span-2" :table="$managerTable" :rows="$managerRows"/>
    </div>

    <nav class="tabs" aria-label="{{ __('Siyahı') }}">
        <a href="{{ route('debts.index', array_merge(request()->except(['tab', 'debts_page']), [])) }}" @class(['tab', 'active' => $tab === 'debtors'])>{{ __('Borclular') }} ({{ Fmt::number($summary->debtors) }})</a>
        <a href="{{ route('debts.index', array_merge(request()->except('debts_page'), ['tab' => 'advances'])) }}" @class(['tab', 'active' => $tab === 'advances'])>{{ __('Avans / artıq ödəyənlər') }} ({{ Fmt::number($summary->advance_count) }})</a>
    </nav>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
