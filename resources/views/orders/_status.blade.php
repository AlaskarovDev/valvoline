@php
    $labels = ['open' => __('Rezervdə'), 'shipped' => __('Təhvil verilib'), 'invoiced' => __('Fakturalanıb'), 'cancelled' => __('Ləğv edilib')];
@endphp
@if (isset($labels[$status]))
    <span @class(['badge', 'badge-warn' => $status === 'open', 'badge-primary' => $status === 'invoiced', 'badge-neg' => $status === 'cancelled'])>{{ $labels[$status] }}</span>
@endif
