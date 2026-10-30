<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('purchase-orders.suggest') }}" class="btn btn-sm btn-primary sm:ml-auto"><x-icon name="plus" size="14"/> {{ __('Satınalma təklifindən yeni sifariş') }}</a>
    </div>
    <x-filters :filters="$filters" :show="[]" :own="['state']"><x-state-filter/></x-filters>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
