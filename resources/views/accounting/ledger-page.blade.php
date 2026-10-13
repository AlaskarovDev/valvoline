<x-layouts.app :title="$title">
    <div><a href="{{ route('accounting.balance', ['year' => $year]) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Balans') }}</a></div>
    <section class="card overflow-hidden">
        @include('accounting.ledger')
    </section>
</x-layouts.app>
