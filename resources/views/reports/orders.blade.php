<x-layouts.app :title="$title">
    @if ($profit)
        <x-filters :filters="$filters" :own="['only']">
            <label class="flex items-center gap-1.5 text-xs text-muted">
                <input type="checkbox" name="only" value="loss" @checked(request('only') === 'loss') onchange="this.form.submit()" class="size-4">
                {{ __('Yalnız zərərlilər') }} ({{ $lossCount }})
            </label>
        </x-filters>
        <x-table :table="$table" :rows="$rows"/>
    @else
        <x-filters :filters="$filters" :own="['state']"><x-state-filter/></x-filters>
        <x-table :table="$table" :rows="$rows">
            <x-slot:actions>
                <a href="{{ route('orders.picking') }}" target="_blank" rel="noopener" class="btn btn-sm"><x-icon name="stock" size="14"/> {{ __('Yığım vərəqi') }}</a>
                <a href="{{ route('orders.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Sifariş əlavə et') }}</a>
            </x-slot:actions>
        </x-table>
    @endif
</x-layouts.app>
