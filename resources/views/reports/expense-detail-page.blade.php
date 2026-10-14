<x-layouts.app :title="$title">
    <div><a href="{{ url()->previous() }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a></div>
    <section class="card overflow-hidden">
        @include($partial ?? 'reports.expense-detail')
    </section>
</x-layouts.app>
