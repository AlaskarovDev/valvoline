<x-layouts.app :title="$title">
    <div class="flex flex-wrap gap-2">
        @foreach ($types as $key => $label)
            @continue(in_array($key, ['cash_opening', 'bank_opening', 'bank_transfer'], true) && ! auth()->user()->isAdmin())
            <a href="{{ route('finance.documents.create', ['type' => $key]) }}" class="btn btn-sm"><x-icon name="plus" size="14"/> {{ $label }}</a>
        @endforeach
    </div>
    <x-filters :filters="$filters" :own="['type', 'state']">
        <x-state-filter/>
        <select name="type" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()" aria-label="{{ __('Sənəd növü') }}">
            <option value="">{{ __('Bütün sənəd növləri') }}</option>
            @foreach ($types as $k => $l)<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach
        </select>
    </x-filters>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
