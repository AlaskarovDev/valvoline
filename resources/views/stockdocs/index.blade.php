<x-layouts.app :title="$title">
    <div class="flex flex-wrap gap-2">
        @foreach ($types as $key => $label)
            <a href="{{ route('stock-docs.create', ['type' => $key]) }}" @class(['btn btn-sm', 'btn-primary' => $type === $key])><x-icon name="plus" size="14"/> {{ $label }}</a>
        @endforeach
    </div>
    @if ($type)
        <div class="card px-4 py-3 text-sm text-muted">{{ $help[$type] }}</div>
    @endif
    <x-filters :filters="$filters" :own="['type']">
        <select name="type" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()" aria-label="{{ __('Sənəd növü') }}">
            <option value="">{{ __('Bütün sənəd növləri') }}</option>
            @foreach ($types as $k => $l)<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach
        </select>
    </x-filters>
    <x-table :table="$table" :rows="$rows">
        <x-slot:actions>
            <a href="{{ route('stock-docs.create', ['type' => $type ?? 'count_surplus']) }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Yeni sənəd') }}</a>
        </x-slot:actions>
    </x-table>
</x-layouts.app>
