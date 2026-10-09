@php use App\Http\Controllers\Reports\MarginController; @endphp
<x-layouts.app :title="$title">
    <x-filters :filters="$filters" :own="['dim']"/>

    <nav class="tabs" aria-label="Ölçü">
        @foreach (MarginController::DIMENSIONS as $key => $label)
            <a href="{{ route('margin.index', $filters->query(['dim' => $key])) }}" @class(['tab', 'active' => $dimension === $key])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-chart title="Marja – satış həcmi" sub="Hər nöqtə bir {{ mb_strtolower(MarginController::DIMENSIONS[$dimension]) }} (ən böyük 150). Nöqtəyə klik → detal" :spec="$scatter" :height="340"/>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
