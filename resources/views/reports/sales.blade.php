@php use App\Http\Controllers\Reports\SalesController; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :own="['gran', 'dim', 'measure']">
        <div class="flex flex-wrap items-center gap-1.5">
            <label class="sr-only" for="gran">{{ __('Dövr') }}</label>
            <select id="gran" name="gran" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
                <option value="month" @selected($granularity === 'month')>{{ __('Aylıq') }}</option>
                <option value="year" @selected($granularity === 'year')>{{ __('İllik') }}</option>
            </select>
            <label class="sr-only" for="dim">{{ __('Sütunlar') }}</label>
            <select id="dim" name="dim" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
                @foreach (SalesController::dimensions() as $key => $label)
                    <option value="{{ $key }}" @selected($dimension === $key)>{{ __('Sütun') }}: {{ $label }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="measure">{{ __('Göstərici') }}</label>
            <select id="measure" name="measure" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
                @foreach (SalesController::measures() as $key => $label)
                    <option value="{{ $key }}" @selected($measure === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </x-filters>

    <x-chart :title="SalesController::measures()[$measure]" :sub="$filters->label()" :spec="$chart" :height="320"/>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
