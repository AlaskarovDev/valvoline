@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['product', 'group', 'category', 'series']" :dates="false" :own="['asof', 'warehouse', 'only']">
        <label class="flex items-center gap-1.5 text-xs text-muted" for="asof">
            Tarixə qalıq
            <input id="asof" type="date" name="asof" value="{{ $asOf->toDateString() }}" class="input h-[30px] w-[140px] text-xs">
        </label>
        <label class="sr-only" for="warehouse">Anbar</label>
        <select id="warehouse" name="warehouse" class="select h-[30px] w-auto text-xs">
            <option value="">Bütün anbarlar</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected($warehouseId === $w->id)>{{ $w->name }}</option>
            @endforeach
        </select>
        <label class="sr-only" for="only">Göstər</label>
        <select id="only" name="only" class="select h-[30px] w-auto text-xs">
            <option value="" @selected(! $only)>Qalığı olanlar</option>
            <option value="low" @selected($only === 'low')>Az qalanlar</option>
            <option value="negative" @selected($only === 'negative')>Mənfi qalıq</option>
            <option value="all" @selected($only === 'all')>Hamısı (sıfırlar daxil)</option>
        </select>
    </x-filters>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi label="Anbar dəyəri (maya)" :value="Fmt::money($kpi['value'])" :meta="$asOf->format('d.m.Y').' tarixinə'" :big="true"/>
        <x-kpi label="Qalıq" :value="Fmt::number($kpi['qty']).' ədəd'" :meta="Fmt::number($kpi['liters']).' litr · '.$kpi['skus'].' çeşid'"/>
        <x-kpi label="Satış qiyməti ilə" :value="Fmt::money($kpi['sale_value'])" :meta="'potensial mənfəət '.Fmt::money($kpi['sale_value'] - $kpi['value'])"/>
        <x-kpi label="Xəbərdarlıqlar" :value="$kpi['low'].' az qalıb'" :meta="$kpi['negative'].' mal mənfi qalıqda'" :tone="$kpi['low'] + $kpi['negative'] > 0 ? 'warn' : null"/>
    </div>

    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
