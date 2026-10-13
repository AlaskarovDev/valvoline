@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('purchases.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Partiyalar') }}</a>
        <span class="badge">{{ Fmt::date($batch->date) }}</span>
        @if ($batch->date_source)<span class="badge">{{ __('Tarix mənbəyi') }}: {{ $batch->date_source }}</span>@endif
        @if ($batch->batch_no == 0)<span class="badge badge-primary">{{ __('Açılış qalığı') }}</span>@endif
    </div>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Çeşid')" :value="Fmt::number($summary->skus)"/>
        <x-kpi :label="__('Ədəd')" :value="Fmt::qty($summary->qty)"/>
        <x-kpi :label="__('Maya cəmi')" :value="Fmt::money($summary->cost)" :meta="__('faylda').': '.Fmt::money($batch->total_cost)"/>
        <x-kpi :label="__('Satış sürəti')" :value="Fmt::pct($summary->qty > 0 ? $summary->sold / $summary->qty : null)" :meta="__(':n ədəd satılıb', ['n' => Fmt::qty($summary->sold)])"/>
    </div>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
