<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('suppliers.index') }}" @class(['btn btn-sm', 'btn-primary' => request('only') !== 'debt'])>{{ __('Hamısı') }}</a>
        <a href="{{ route('suppliers.index', ['only' => 'debt']) }}" @class(['btn btn-sm', 'btn-primary' => request('only') === 'debt'])>{{ __('Borcumuz olanlar') }}</a>
        <a href="{{ route('suppliers.create') }}" class="btn btn-sm btn-primary sm:ml-auto"><x-icon name="plus" size="14"/> {{ __('Yeni təchizatçı') }}</a>
    </div>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
