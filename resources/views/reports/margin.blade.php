@php use App\Http\Controllers\Reports\MarginController; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :own="['dim']"/>

    <nav class="tabs" aria-label="Ölçü">
        @foreach (MarginController::DIMENSIONS as $key => $label)
            <a href="{{ route('margin.index', $filters->query(['dim' => $key])) }}" @class(['tab', 'active' => $dimension === $key])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-chart title="Satış həcmi və marja" :sub="'Xalis satışa görə ən böyük '.MarginController::CHART_ITEMS.' · sütunun sonunda marja · klik → detal'" :spec="$chart" :height="$chartHeight">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-3 text-xs text-muted" aria-label="Marja rəngləri">
                @foreach (MarginController::MARGIN_BANDS as $color => [$min, $label])
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm" style="background: var({{ $color }})"></span>{{ $label }}</span>
                @endforeach
            </div>
        </x-slot:actions>
    </x-chart>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
