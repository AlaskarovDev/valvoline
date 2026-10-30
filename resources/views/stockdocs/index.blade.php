<x-layouts.app :title="$title">
    {{-- növ filtrləri (siyahı) + seçilmiş növ üçün «Yeni» düyməsi --}}
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('stock-docs.index') }}" @class(['btn btn-sm', 'btn-primary' => ! $type])>{{ __('Hamısı') }}</a>
        @foreach ($types as $key => $label)
            <a href="{{ route('stock-docs.index', ['type' => $key]) }}" @class(['btn btn-sm', 'btn-primary' => $type === $key])>{{ $label }}</a>
        @endforeach
        @if ($type)
            <a href="{{ route('stock-docs.create', ['type' => $type]) }}" class="btn btn-sm btn-primary sm:ml-auto"><x-icon name="plus" size="14"/> {{ __('Yeni: :type', ['type' => $types[$type]]) }}</a>
        @endif
    </div>
    @if ($type)
        <div class="card px-4 py-3 text-sm text-muted">{{ $help[$type] }}</div>
    @endif
    <x-filters :filters="$filters" :own="['type', 'state']">
        <x-state-filter/>
        <select name="type" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()" aria-label="{{ __('Sənəd növü') }}">
            <option value="">{{ __('Bütün sənəd növləri') }}</option>
            @foreach ($types as $k => $l)<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach
        </select>
    </x-filters>
    <x-table :table="$table" :rows="$rows">
        <x-slot:actions>
            @if ($type)
                <a href="{{ route('stock-docs.create', ['type' => $type]) }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Yeni: :type', ['type' => $types[$type]]) }}</a>
            @else
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($types as $key => $label)<a href="{{ route('stock-docs.create', ['type' => $key]) }}" class="btn btn-sm"><x-icon name="plus" size="14"/> {{ $label }}</a>@endforeach
                </div>
            @endif
        </x-slot:actions>
    </x-table>
</x-layouts.app>
