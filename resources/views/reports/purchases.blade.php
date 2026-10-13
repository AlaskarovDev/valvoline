<x-layouts.app :title="$title">
    <x-chart :title="__('Partiyaların maya cəmi')" :sub="__('Sütuna klik → partiyanın malları')" :spec="$chart" :height="300"/>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
