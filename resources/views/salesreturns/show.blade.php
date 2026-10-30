@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('sales-returns.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($doc->date) }}</span>
        <a href="{{ route('customers.show', $doc->customer) }}" class="badge badge-primary">{{ $doc->customer->name }} · {{ $doc->customer->code }}</a>
        @if ($doc->invoice)<a href="{{ route('invoices.show', $doc->invoice) }}" class="badge badge-primary">{{ __('Faktura') }}: {{ $doc->invoice->doc_no }}</a>@endif
        <span class="badge badge-warn">{{ $reasons[$doc->reason] ?? $doc->reason }}</span>
        <span class="badge">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</span>
        @if ($doc->manager)<span class="badge">{{ __('Menecer') }}: {{ $doc->manager->name }}</span>@endif
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('sales-returns.show', [$doc, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
            @if (! $doc->cancelled_at && $doc->invoice?->etax_serial)
                <form method="POST" action="{{ route('einvoices.export-returns') }}">@csrf<input type="hidden" name="ids[]" value="{{ $doc->id }}">
                    <button class="btn btn-sm" title="{{ __('Əsas e-qaimə: :serial', ['serial' => $doc->invoice->etax_serial]) }}"><x-icon name="download" size="14"/> {{ __('e-qaimə Excel') }}</button></form>
            @endif
            @unless ($doc->cancelled_at)<x-cancel-button :action="route('sales-returns.destroy', $doc)" :confirm="__('Qaytarma ləğv edilsin? Müştəri borcu bərpa olunacaq, mal anbardan çıxacaq.')"/>@endunless
        </div>
    </div>
    <x-cancelled-banner :doc="$doc"/>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        <x-kpi :label="__('Müştəri borcundan çıxılır')" :value="Fmt::money($doc->total_amount)" :big="true"/>
        <x-kpi :label="__('ƏDV')" :value="Fmt::money($doc->vat_amount)"/>
        <x-kpi :label="__('ƏDV-siz məbləğ')" :value="Fmt::money($doc->total_amount - $doc->vat_amount)"/>
    </div>
    @if ($doc->note)<div class="card px-4 py-3 text-sm"><span class="label">{{ __('Qeyd') }}</span>{{ $doc->note }}</div>@endif
    <section class="card overflow-hidden"><div class="tbl-wrap relative">
        <table class="tbl">
            <thead><tr><th>#</th><th>{{ __('Kod') }}</th><th>{{ __('Mal') }}</th><th class="num">{{ __('Miqdar') }}</th><th class="num">{{ __('Qiymət') }}</th><th class="num">{{ __('ƏDV') }}</th><th class="num">{{ __('Məbləğ') }}</th></tr></thead>
            <tbody>@foreach ($lines as $l)<tr><td class="text-muted">{{ $l->row_no }}</td><td class="font-mono text-xs">{{ $l->product_code }}</td><td class="wrap">{{ $l->product_name }}</td><td class="num">{{ Fmt::qty($l->qty) }}</td><td class="num">{{ Fmt::money($l->price) }}</td><td class="num">{{ Fmt::money($l->vat_amount) }}</td><td class="num font-semibold">{{ Fmt::money($l->net_amount) }}</td></tr>@endforeach</tbody>
        </table>
    </div></section>
    <p class="text-xs text-muted">{{ __('Yaradıb') }}: {{ $doc->creator?->name ?? '—' }} · {{ $doc->created_at?->format('d.m.Y H:i') }}</p>
    <x-audit-history type="SalesReturnDocument" :id="$doc->id" :no="$doc->doc_no"/>
</x-layouts.app>
