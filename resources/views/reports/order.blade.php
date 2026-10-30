@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : ($profit ? route('order-profit.index') : route('orders.index')) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($order->date) }}</span>
        @if ($order->invoice_no)<span class="badge">{{ __('Qaimə') }} {{ $order->invoice_no }}</span>@endif
        @if ($order->customer)
            <a href="{{ route('customers.show', $order->customer) }}" class="badge badge-primary">{{ $order->customer->name }} · {{ $order->customer->code }}</a>
        @else
            <span class="badge badge-warn">{{ __('Müştəri') }}: {{ $order->customer_name ?? '—' }} ({{ __('gözləmədə') }})</span>
        @endif
        @if ($order->manager)<span class="badge">{{ __('Menecer') }}: {{ $order->manager->name }}</span>@endif
        @if ($order->warehouse)<span class="badge">{{ __('Anbar') }}: {{ $order->warehouse->name }}</span>@endif
        @if ($order->workplace)<span class="badge">{{ __('İş yeri') }}: {{ $order->workplace->name }}</span>@endif
        @if ($order->source === 'manual')
            @include('orders._status', ['status' => $order->status])
            @foreach ($deliveries as $d)<a href="{{ route('deliveries.show', $d->id) }}" @class(['badge', 'badge-primary' => ! $d->cancelled_at, 'line-through' => $d->cancelled_at]) @if($d->cancelled_at) title="{{ __('Ləğv edilib') }}" @endif><x-icon name="truck" size="11"/> {{ $d->doc_no }}</a>@endforeach
            @foreach ($invoices as $inv)<a href="{{ route('invoices.show', $inv->id) }}" @class(['badge', 'badge-primary' => ! $inv->cancelled_at, 'line-through' => $inv->cancelled_at]) @if($inv->cancelled_at) title="{{ __('Ləğv edilib') }}" @endif><x-icon name="invoice" size="11"/> {{ $inv->doc_no }}</a>@endforeach
            <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                <a href="{{ route('orders.print', $order) }}" target="_blank" rel="noopener" class="btn btn-sm"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
                @if ($order->status === 'open')
                    <a href="{{ route('orders.edit', $order) }}" class="btn btn-sm"><x-icon name="edit" size="14"/> {{ __('Düzəliş et') }}</a>
                    <x-cancel-button :action="route('orders.destroy', $order)" :confirm="__('Sifariş ləğv edilsin? Rezerv azad olunacaq.')"/>
                    <form method="POST" action="{{ route('orders.send', $order) }}" onsubmit="return confirm(@js(__('Təhvil-təslim sənədi yaradılsın? Mal real stokdan çıxacaq.')))">
                        @csrf <button class="btn btn-sm"><x-icon name="send" size="14"/> {{ __('Sifarişi göndər') }}</button>
                    </form>
                @endif
                @if (! in_array($order->status, ['invoiced', 'cancelled'], true))
                    <form method="POST" action="{{ route('orders.invoice', $order) }}" onsubmit="return confirm(@js($order->status === 'open' ? __('Təhvil-təslim sənədi və satış fakturası yaradılsın?') : __('Satış fakturası yaradılsın?')))">
                        @csrf <button class="btn btn-sm btn-primary"><x-icon name="invoice" size="14"/> {{ __('Fakturala') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
    @if ($order->source === 'manual' && $order->status === 'open')
        <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--warning)">{{ __('Sifariş rezervdədir: mal rezerv stokdan çıxıb, real stoka və müştəri borcuna hələ təsir etmir. «Sifarişi göndər» — təhvil-təslim (real stok), «Fakturala» — təhvil-təslim + faktura (borc).') }}</div>
    @endif
    <x-cancelled-banner :doc="$order"/>
    @if ($order->note)
        <div class="card px-4 py-3 text-sm"><span class="label">{{ __('Əlavə qeydlər') }}</span>{{ $order->note }}</div>
    @endif

    @if ($profit)
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <x-kpi :label="__('Məbləğ')" :value="Fmt::money($order->total_amount)"/>
            <x-kpi :label="__('Maya')" :value="Fmt::money($order->total_cogs)"/>
            <x-kpi :label="__('Qazanc')" :value="Fmt::money($order->gross_profit)" :tone="$order->gross_profit < 0 ? 'neg' : 'pos'" :meta="__('marja').' '.Fmt::pct($margin)"/>
            <x-kpi :label="__('Qaytarma')" :value="Fmt::money($order->return_amount)" :meta="__('maya').' '.Fmt::money($order->return_cogs)"/>
            <x-kpi :label="__('Qaytarmadan sonra xalis qazanc')" :value="Fmt::money($order->net_profit)" :tone="$order->net_profit < 0 ? 'neg' : 'pos'"/>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-kpi :label="__('Məbləğ')" :value="Fmt::money($order->total_amount)"/>
            <x-kpi :label="__('Sətir')" :value="Fmt::number($order->line_count)"/>
            <x-kpi :label="__('Qaytarma')" :value="Fmt::money($order->return_amount)"/>
            <x-kpi :label="__('Tarix')" :value="Fmt::date($order->date)"/>
        </div>
    @endif

    <x-table :table="$table" :rows="$rows"/>
    @if ($returnRows->total() > 0)
        <x-table :table="$returnTable" :rows="$returnRows"/>
    @endif
    @if ($order->source === 'manual')
        <x-audit-history type="SalesOrder" :id="$order->id" :no="$order->order_no"/>
    @endif
</x-layouts.app>
