<nav class="flex gap-1.5" aria-label="{{ __('Görünüş') }}">
    <a href="{{ route('accounting.balance', array_filter(['year' => $year])) }}" @class(['chip', 'active' => $view === 'official'])>{{ __('Rəsmi (mühasibat faylı)') }}</a>
    <a href="{{ route('accounting.balance', array_filter(['year' => $year, 'view' => 'mgmt'])) }}" @class(['chip', 'active' => $view === 'mgmt'])>{{ __('İdarəetmə (sistem importları)') }}</a>
</nav>
