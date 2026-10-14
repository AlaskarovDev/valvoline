@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['direction']" :own="['gran', 'cat', 'profit', 'src']">
        <label class="sr-only" for="gran">{{ __('Dövr') }}</label>
        <select id="gran" name="gran" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
            <option value="month" @selected($granularity === 'month')>{{ __('Aylıq') }}</option>
            <option value="year" @selected($granularity === 'year')>{{ __('İllik') }}</option>
        </select>
        <label class="sr-only" for="src">{{ __('Mənbə') }}</label>
        <select id="src" name="src" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
            <option value="all" @selected($source === 'all')>{{ __('Kassa + rəsmi') }}</option>
            <option value="cash" @selected($source === 'cash')>{{ __('Yalnız kassa') }}</option>
            <option value="bank" @selected($source === 'bank')>{{ __('Yalnız rəsmi (bank)') }}</option>
        </select>
        <label class="sr-only" for="profit">{{ __('Mənfəətə təsir') }}</label>
        <select id="profit" name="profit" class="select h-[30px] w-auto text-xs">
            <option value="">{{ __('Siyahı: bütün əməliyyatlar') }}</option>
            <option value="yes" @selected(request('profit') === 'yes')>{{ __('Mənfəətə təsir edənlər') }}</option>
            <option value="no" @selected(request('profit') === 'no')>{{ __('Mənfəətə təsirsizlər') }}</option>
        </select>
        @if ($category)
            <input type="hidden" name="cat" value="{{ $category }}">
            <a href="{{ route('expenses.index', request()->except(['cat', 'cash_page'])) }}" class="chip active">{{ __('Kateqoriya filtri ✕') }}</a>
        @endif
    </x-filters>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Əməliyyat xərcləri')" :value="Fmt::money($grand)" :meta="$filters->label()" tone="neg"/>
        <x-kpi :label="__('Kassa xərcləri')" :value="Fmt::money($cashTotal)" :meta="__('08 Xərclər faylı')"/>
        <x-kpi :label="__('Rəsmi xərclər (bank)')" :value="Fmt::money($bankTotal)" :meta="__('Mühasibat Cash Flow · kassada yoxdur')" :href="route('accounting.reconcile')"/>
        <x-kpi :label="__('İstiqamət')" :value="$filters->direction && $filters->direction !== 'all' ? $filters->direction : __('Hamısı')" :meta="__('Rəsmi xərclər yalnız VALVO-ya aiddir')"/>
    </div>

    <x-chart :title="__('Xərc trendi')" :sub="__('Ən böyük 6 kateqoriya')" :spec="$chart" :height="320"/>
    <x-table :table="$pivot" :rows="$pivotRows"/>
    <x-table :table="$listTable" :rows="$listRows"/>
</x-layouts.app>
