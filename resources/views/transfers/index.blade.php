@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Yolda olan transferlər')" :value="Fmt::number($kpi['open'])" :href="route('transfers.index', ['status' => 'open'])"
               :meta="$kpi['stuck'] ? __(':n transfer 3 gündən çoxdur yoldadır', ['n' => $kpi['stuck']]) : null" :tone="$kpi['stuck'] ? 'warn' : null"/>
        <x-kpi :label="__('Yolda olan mal')" :value="Fmt::qty($kpi['transit_qty'])" :meta="Fmt::money($kpi['transit_value']).' '.__('maya ilə')"/>
        <x-kpi :label="__('Qaralamalar')" :value="Fmt::number($kpi['draft'])" :href="route('transfers.index', ['status' => 'draft'])"/>
        <a href="{{ route('transfers.create') }}" class="kpi flex flex-col justify-center gap-1 bg-[var(--primary-soft)]">
            <span class="flex items-center gap-2 font-semibold text-primary-text"><x-icon name="plus" size="16"/> {{ __('Yeni transfer') }}</span>
            <span class="text-xs text-muted">{{ __('Maşına yükləmə, qaytarma, anbarlar arası') }}</span>
        </a>
    </div>

    <x-filters :filters="$filters" :own="['status', 'warehouse']">
        <select name="status" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
            <option value="">{{ __('Bütün statuslar') }}</option>
            <option value="open" @selected($status === 'open')>{{ __('Yolda (açıq)') }}</option>
            @foreach ($statuses as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
        </select>
        <select name="warehouse" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()" aria-label="{{ __('Anbar') }}">
            <option value="">{{ __('Bütün anbarlar') }}</option>
            @foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((string) request('warehouse') === (string) $w->id)>{{ $w->name }}</option>@endforeach
        </select>
    </x-filters>
    <x-table :table="$table" :rows="$rows">
        <x-slot:actions><a href="{{ route('transfers.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Yeni transfer') }}</a></x-slot:actions>
    </x-table>
</x-layouts.app>
