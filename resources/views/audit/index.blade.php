<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="[]" :own="['q', 'user', 'outcome']">
        <label class="sr-only" for="a-q">{{ __('Axtarış') }}</label>
        <input id="a-q" type="search" name="q" value="{{ $search }}" class="input h-[30px] w-full text-xs sm:w-56" placeholder="{{ __('Sənəd №, istifadəçi, IP…') }}">
        <select name="user" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()" aria-label="{{ __('İstifadəçi') }}">
            <option value="">{{ __('Bütün istifadəçilər') }}</option>
            @foreach ($users as $id => $name)<option value="{{ $id }}" @selected((string) request('user') === (string) $id)>{{ $name }}</option>@endforeach
        </select>
        <select name="outcome" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()" aria-label="{{ __('Vəziyyət') }}">
            <option value="">{{ __('Bütün nəticələr') }}</option>
            @foreach ($outcomes as $k => $l)<option value="{{ $k }}" @selected(request('outcome') === $k)>{{ $l }}</option>@endforeach
        </select>
    </x-filters>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
