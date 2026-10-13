@php use App\Http\Controllers\Reports\MarginController; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :own="['dim']"/>

    <nav class="tabs" aria-label="{{ __('Ölçü') }}">
        @foreach (MarginController::dimensions() as $key => $label)
            <a href="{{ route('margin.index', $filters->query(['dim' => $key])) }}" @class(['tab', 'active' => $dimension === $key])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-chart :title="__('Satış həcmi və marja')" :sub="__('Xalis satışa görə ən böyük :n · sütunun sonunda marja · klik → detal', ['n' => MarginController::CHART_ITEMS])" :spec="$chart" :height="$chartHeight">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-3 text-xs text-muted" aria-label="{{ __('Marja rəngləri') }}">
                @foreach (MarginController::marginBands() as $color => [$min, $label])
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm" style="background: var({{ $color }})"></span>{{ $label }}</span>
                @endforeach
            </div>
        </x-slot:actions>
    </x-chart>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
