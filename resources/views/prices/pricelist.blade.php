@php $isAdmin = auth()->user()->isAdmin(); @endphp
<x-layouts.app :title="$title">
    <section class="card">
        <form method="GET" class="flex flex-wrap items-end gap-2 px-4 py-3">
            <div><label class="label" for="pl-customer">{{ __('Müştəri') }}</label>
                <select id="pl-customer" name="customer" class="select h-[34px] max-w-64 text-xs">
                    <option value="">—</option>
                    @foreach ($customers as $c)<option value="{{ $c->id }}" @selected($customer?->id === $c->id)>{{ $c->name }} · {{ $c->code }}</option>@endforeach
                </select></div>
            <div><label class="label" for="pl-list">{{ __('və ya qiymət növü') }}</label>
                <select id="pl-list" name="list" class="select h-[34px] text-xs">
                    <option value="">{{ __('Əsas') }}</option>
                    @foreach ($priceLists as $l)<option value="{{ $l->id }}" @selected($listId === $l->id)>{{ $l->name }}</option>@endforeach
                </select></div>
            <div><label class="label" for="pl-brand">{{ __('Marka') }}</label>
                <select id="pl-brand" name="brand" class="select h-[34px] text-xs">
                    <option value="">{{ __('Hamısı') }}</option>
                    @foreach ($brands as $b)<option value="{{ $b->id }}" @selected((string) request('brand') === (string) $b->id)>{{ $b->name }}</option>@endforeach
                </select></div>
            <div><label class="label" for="pl-date">{{ __('Tarix') }}</label><input id="pl-date" type="date" name="date" value="{{ $date->toDateString() }}" class="input h-[34px] text-xs"></div>
            <button class="btn btn-sm btn-primary h-[34px]">{{ __('Göstər') }}</button>
            <button type="button" class="btn btn-sm h-[34px] sm:ml-auto" onclick="window.print()"><x-icon name="print" size="14"/> {{ __('Çap et') }}</button>
        </form>
        @if ($customer && $isAdmin)
            <form method="POST" action="{{ route('customers.pricelist', $customer) }}" class="flex flex-wrap items-end gap-2 border-t border-line px-4 py-3 text-sm">
                @csrf @method('PUT')
                <span class="text-muted">{{ __('Müştərinin qiymət növü') }}:</span>
                <select name="price_list_id" class="select h-[30px] w-auto text-xs" aria-label="{{ __('Müştərinin qiymət növü') }}">
                    <option value="">{{ __('Əsas') }}</option>
                    @foreach ($priceLists as $l)<option value="{{ $l->id }}" @selected($customer->price_list_id === $l->id)>{{ $l->name }}</option>@endforeach
                </select>
                <button class="btn btn-sm">{{ __('Yadda saxla') }}</button>
            </form>
        @endif
    </section>
    <h2 class="text-base font-semibold">{{ $heading }}</h2>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
