<x-layouts.app :title="$title">
    <div><a href="{{ route('accounting.chart', ['year' => $year]) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Hesablar planı') }}</a></div>
    <section class="card overflow-hidden">
        @include('accounting.source')
    </section>
</x-layouts.app>
