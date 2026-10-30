<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('purchase-docs.index') }}" @class(['btn btn-sm', 'btn-primary' => ! $type])>{{ __('Hamısı') }}</a>
        @foreach ($types as $key => $label)
            <a href="{{ route('purchase-docs.index', ['type' => $key]) }}" @class(['btn btn-sm', 'btn-primary' => $type === $key])>{{ $label }}</a>
        @endforeach
        <a href="{{ route('purchase-docs.create', ['type' => $type === 'return' ? 'return' : 'receipt']) }}" class="btn btn-sm btn-primary sm:ml-auto"><x-icon name="plus" size="14"/> {{ __('Yeni: :type', ['type' => $types[$type === 'return' ? 'return' : 'receipt']]) }}</a>
    </div>
    <x-filters :filters="$filters" :show="[]" :own="['type', 'state']"><x-state-filter/></x-filters>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
