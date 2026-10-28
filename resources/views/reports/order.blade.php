@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('orders.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($order->date) }}</span>
        @if ($order->invoice_no)<span class="badge">{{ __('Qaimə') }} {{ $order->invoice_no }}</span>@endif
        @if ($order->customer)
            <a href="{{ route('customers.show', $order->customer) }}" class="badge badge-primary">{{ $order->customer->name }} · {{ $order->customer->code }}</a>
        @else
            <span class="badge badge-warn">{{ __('Müştəri') }}: {{ $order->customer_name ?? '—' }} ({{ __('gözləmədə') }})</span>
        @endif
        @if ($order->manager)<span class="badge">{{ __('Menecer') }}: {{ $order->manager->name }}</span>@endif
        @if ($order->warehouse)<span class="badge">{{ __('Anbar') }}: {{ $order->warehouse->name }}</span>@endif
        @if ($order->source === 'manual')
            <span class="badge badge-primary">{{ __('Sistemdə yaradılıb') }}</span>
            <div class="ml-auto flex items-center gap-2">
                <a href="{{ route('orders.edit', $order) }}" class="btn btn-sm"><x-icon name="edit" size="14"/> {{ __('Düzəliş et') }}</a>
                <form method="POST" action="{{ route('orders.destroy', $order) }}" onsubmit="return confirm(@js(__('Sifariş silinsin?')))">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger"><x-icon name="trash" size="14"/> {{ __('Sil') }}</button>
                </form>
            </div>
        @endif
    </div>
    @if ($order->note)
        <div class="card px-4 py-3 text-sm"><span class="label">{{ __('Əlavə qeydlər') }}</span>{{ $order->note }}</div>
    @endif

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-kpi :label="__('Məbləğ')" :value="Fmt::money($order->total_amount)"/>
        <x-kpi :label="__('Maya')" :value="Fmt::money($order->total_cogs)"/>
        <x-kpi :label="__('Qazanc')" :value="Fmt::money($order->gross_profit)" :tone="$order->gross_profit < 0 ? 'neg' : 'pos'" :meta="__('marja').' '.Fmt::pct($margin)"/>
        <x-kpi :label="__('Qaytarma')" :value="Fmt::money($order->return_amount)" :meta="__('maya').' '.Fmt::money($order->return_cogs)"/>
        <x-kpi :label="__('Qaytarmadan sonra xalis qazanc')" :value="Fmt::money($order->net_profit)" :tone="$order->net_profit < 0 ? 'neg' : 'pos'"/>
    </div>

    <x-table :table="$table" :rows="$rows"/>
    @if ($returnRows->total() > 0)
        <x-table :table="$returnTable" :rows="$returnRows"/>
    @endif
</x-layouts.app>
