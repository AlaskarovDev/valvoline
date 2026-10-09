@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['direction']" :own="['gran', 'cat', 'profit']">
        <label class="sr-only" for="gran">Dövr</label>
        <select id="gran" name="gran" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
            <option value="month" @selected($granularity === 'month')>Aylıq</option>
            <option value="year" @selected($granularity === 'year')>İllik</option>
        </select>
        <label class="sr-only" for="profit">Mənfəətə təsir</label>
        <select id="profit" name="profit" class="select h-[30px] w-auto text-xs">
            <option value="">Siyahı: bütün əməliyyatlar</option>
            <option value="yes" @selected(request('profit') === 'yes')>Mənfəətə təsir edənlər</option>
            <option value="no" @selected(request('profit') === 'no')>Mənfəətə təsirsizlər</option>
        </select>
        @if ($category)
            <input type="hidden" name="cat" value="{{ $category }}">
            <a href="{{ route('expenses.index', request()->except(['cat', 'cash_page'])) }}" class="chip active">Kateqoriya filtri ✕</a>
        @endif
    </x-filters>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <x-kpi label="Əməliyyat xərcləri" :value="Fmt::money($grand)" :meta="$filters->label()" tone="neg"/>
        <x-kpi label="İstiqamət" :value="$filters->direction && $filters->direction !== 'all' ? $filters->direction : 'Hamısı'" meta="TIR ayrıca biznes xəttidir"/>
        <x-kpi label="Kateqoriya sayı" :value="$pivotRows->total()" :href="route('categories.index')" meta="Bayraqları redaktə et →"/>
    </div>

    <x-chart title="Xərc trendi" sub="Ən böyük 6 kateqoriya" :spec="$chart" :height="320"/>
    <x-table :table="$pivot" :rows="$pivotRows"/>
    <x-table :table="$listTable" :rows="$listRows"/>
</x-layouts.app>
