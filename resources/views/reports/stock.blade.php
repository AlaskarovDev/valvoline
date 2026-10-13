@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['product', 'group', 'category', 'series']" :dates="false" :own="['asof', 'warehouse', 'only']">
        <label class="flex items-center gap-1.5 text-xs text-muted" for="asof">
            {{ __('Tarixə qalıq') }}
            <input id="asof" type="date" name="asof" value="{{ $asOf->toDateString() }}" class="input h-[30px] w-[140px] text-xs">
        </label>
        <label class="sr-only" for="warehouse">{{ __('Anbar') }}</label>
        <select id="warehouse" name="warehouse" class="select h-[30px] w-auto text-xs">
            <option value="">{{ __('Bütün anbarlar') }}</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected($warehouseId === $w->id)>{{ $w->name }}</option>
            @endforeach
        </select>
        <label class="sr-only" for="only">{{ __('Göstər') }}</label>
        <select id="only" name="only" class="select h-[30px] w-auto text-xs">
            <option value="" @selected(! $only)>{{ __('Qalığı olanlar') }}</option>
            <option value="low" @selected($only === 'low')>{{ __('Az qalanlar') }}</option>
            <option value="negative" @selected($only === 'negative')>{{ __('Mənfi qalıq') }}</option>
            <option value="all" @selected($only === 'all')>{{ __('Hamısı (sıfırlar daxil)') }}</option>
        </select>
    </x-filters>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Anbar dəyəri (maya)')" :value="Fmt::money($kpi['value'])" :meta="__(':date tarixinə', ['date' => $asOf->format('d.m.Y')])" :big="true"/>
        <x-kpi :label="__('Qalıq')" :value="__(':n ədəd', ['n' => Fmt::number($kpi['qty'])])" :meta="__(':n litr', ['n' => Fmt::number($kpi['liters'])]).' · '.__(':n çeşid', ['n' => $kpi['skus']])"/>
        <x-kpi :label="__('Satış qiyməti ilə')" :value="Fmt::money($kpi['sale_value'])" :meta="__('potensial mənfəət').' '.Fmt::money($kpi['sale_value'] - $kpi['value'])"/>
        <x-kpi :label="__('Xəbərdarlıqlar')" :value="__(':n az qalıb', ['n' => $kpi['low']])" :meta="__(':n mal mənfi qalıqda', ['n' => $kpi['negative']])" :tone="$kpi['low'] + $kpi['negative'] > 0 ? 'warn' : null"/>
    </div>

    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
