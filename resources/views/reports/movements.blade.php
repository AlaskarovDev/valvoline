@php use App\Support\Fmt; use App\Services\StockService; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :show="['product', 'group', 'category', 'series', 'customer']" :own="['type']">
        <label class="sr-only" for="type">Hərəkət tipi</label>
        <select id="type" name="type" class="select h-[30px] w-auto text-xs">
            <option value="">Bütün tiplər</option>
            @foreach (['opening', 'purchase', 'sale', 'gift', 'internal_use', 'return', 'transfer_out', 'transfer_in', 'adjustment'] as $t)
                <option value="{{ $t }}" @selected($type === $t)>{{ StockService::typeLabel($t) }}</option>
            @endforeach
        </select>
    </x-filters>

    <div class="flex flex-wrap gap-2">
        @foreach ($summary as $key => $s)
            <a href="{{ route('movements.index', array_merge(request()->except('mv_page'), ['type' => $key])) }}" class="card flex items-center gap-3 px-3 py-2 text-xs hover:border-line-strong">
                {!! \App\Http\Controllers\Reports\MovementController::typeBadge($key) !!}
                <span class="num">{{ Fmt::number($s->cnt) }} sətir</span>
                <span class="num {{ $s->qty < 0 ? 'neg' : 'pos' }}">{{ Fmt::qty($s->qty) }} əd.</span>
                <span class="num text-muted">{{ Fmt::money($s->cost) }}</span>
            </a>
        @endforeach
    </div>

    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
