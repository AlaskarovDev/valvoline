@props(['filters', 'show' => ['manager', 'customer', 'product', 'group', 'category', 'series'], 'dates' => true, 'own' => []])
@php
    use App\Reports\FilterOptions;
    use App\Reports\ReportFilters;
    $labels = ['manager' => 'Menecer', 'customer' => 'Müştəri', 'product' => 'Mal', 'group' => 'Məhsul qrupu', 'category' => 'Kateqoriya', 'series' => 'Seriya', 'direction' => 'İstiqamət'];
    $values = ['manager' => $filters->managerId, 'customer' => $filters->customerId, 'product' => $filters->productId, 'group' => $filters->groupId, 'category' => $filters->categoryId, 'series' => $filters->series, 'direction' => $filters->direction];
    $show = array_values(array_filter($show, fn ($f) => ! ($f === 'manager' && $filters->managerLocked)));
    $activeCount = count(array_filter(array_intersect_key($values, array_flip($show)), fn ($v) => $v !== null && $v !== 'all'));
@endphp
<form method="GET" class="card no-print" x-data="{ open: {{ $activeCount > 0 ? 'true' : 'false' }} }">
    <div class="flex flex-wrap items-center gap-2 p-3">
        @if ($dates)
            <div class="-mx-1 flex w-[calc(100%+0.5rem)] gap-1.5 overflow-x-auto px-1 pb-0.5 sm:w-auto sm:flex-wrap sm:overflow-visible">
                @foreach (ReportFilters::PRESETS as $key => $label)
                    <a href="{{ request()->url().'?'.http_build_query($filters->query(['preset' => $key, 'from' => null, 'to' => null])) }}"
                       @class(['chip shrink-0', 'active' => $filters->preset === $key])>{{ $label }}</a>
                @endforeach
            </div>
            <div class="flex w-full items-center gap-1.5 sm:w-auto">
                <label class="sr-only" for="f-from">Başlanğıc tarix</label>
                <input id="f-from" type="date" name="from" value="{{ $filters->fromDate() }}" class="input h-[34px] min-w-0 flex-1 text-xs sm:h-[30px] sm:w-[140px] sm:flex-none" @if($filters->preset === 'all') disabled @endif>
                <span class="text-muted">–</span>
                <label class="sr-only" for="f-to">Son tarix</label>
                <input id="f-to" type="date" name="to" value="{{ $filters->toDate() }}" class="input h-[34px] min-w-0 flex-1 text-xs sm:h-[30px] sm:w-[140px] sm:flex-none" @if($filters->preset === 'all') disabled @endif>
            </div>
        @endif
        {{ $slot }}
        <div class="ml-auto flex items-center gap-1.5">
            @if ($show)
                <button type="button" class="btn btn-sm" @click="open = !open" :aria-expanded="open">
                    <x-icon name="filter" size="14"/> Filtrlər @if($activeCount)<span class="badge badge-primary">{{ $activeCount }}</span>@endif
                </button>
            @endif
            <button class="btn btn-sm btn-primary">Tətbiq et</button>
            <a href="{{ request()->url() }}" class="btn btn-sm">Sıfırla</a>
        </div>
    </div>
    @if ($show)
        <div class="grid grid-cols-1 gap-3 border-t border-line p-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6" x-show="open" x-cloak>
            @foreach ($show as $field)
                <div>
                    <label class="label" for="f-{{ $field }}">{{ $labels[$field] }}</label>
                    <select id="f-{{ $field }}" name="{{ $field }}" class="select">
                        @if ($field === 'direction')
                            <option value="all" @selected($values[$field] === 'all' || $values[$field] === null)>Hamısı</option>
                        @else
                            <option value="">Hamısı</option>
                        @endif
                        @foreach (FilterOptions::get($field, $filters->managerLocked ? $filters->managerId : null) as $id => $text)
                            <option value="{{ $id }}" @selected((string) $values[$field] === (string) $id)>{{ $text }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
    @endif
    @foreach (request()->except(['from', 'to', 'preset', 'manager', 'customer', 'product', 'group', 'category', 'series', 'direction', 'export', 'table']) as $k => $v)
        @if (is_string($v) && ! str_ends_with($k, '_page') && ! in_array($k, $own, true))
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
        @endif
    @endforeach
</form>
