<x-layouts.app :title="$title">
    <div><a href="{{ route('accounting.pl', ['year' => $year]) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Mənfəət-zərər (vergi)') }}</a></div>
    <section class="card overflow-hidden">
        @include('accounting.mz-detail')
    </section>
</x-layouts.app>
