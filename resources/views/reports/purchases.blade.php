<x-layouts.app :title="$title">
    <x-chart title="Partiyaların maya cəmi" sub="Sütuna klik → partiyanın malları" :spec="$chart" :height="300"/>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
