<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :own="['state']"><x-state-filter/></x-filters>
    <x-table :table="$table" :rows="$rows">
        <x-slot:actions>
            <a href="{{ route('orders.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Sifariş əlavə et') }}</a>
        </x-slot:actions>
    </x-table>
</x-layouts.app>
