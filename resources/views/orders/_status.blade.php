@php
    $labels = ['open' => __('Rezervdə'), 'shipped' => __('Təhvil verilib'), 'invoiced' => __('Fakturalanıb')];
@endphp
@if (isset($labels[$status]))
    <span @class(['badge', 'badge-warn' => $status === 'open', 'badge-primary' => $status === 'invoiced'])>{{ $labels[$status] }}</span>
@endif
