@php use App\Support\Fmt; $cur = $doc->currency; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('purchase-docs.index', ['type' => $doc->type]) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($doc->date) }}</span>
        <a href="{{ route('suppliers.show', $doc->supplier) }}" class="badge badge-primary">{{ $doc->supplier->name }} · {{ $doc->supplier->code }}</a>
        <span class="badge">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</span>
        @if ($cur !== 'AZN')<span class="badge">1 {{ $cur }} = {{ rtrim(rtrim(number_format($doc->rate, 6, ',', ' '), '0'), ',') }} ₼</span>@endif
        @if ($doc->supplier_invoice_no)<span class="badge">{{ __('Təchizatçı qaiməsi') }}: {{ $doc->supplier_invoice_no }}@if ($doc->supplier_invoice_date) · {{ Fmt::date($doc->supplier_invoice_date) }}@endif</span>@endif
        @if ($doc->due_date)<span class="badge">{{ __('Ödəniş tarixi') }}: {{ Fmt::date($doc->due_date) }}</span>@endif
        @if ($doc->purchase_order_id)<a href="{{ route('purchase-orders.show', $doc->purchase_order_id) }}" class="badge badge-primary">{{ __('Alış sifarişi') }}</a>@endif
        @if ($doc->original)<a href="{{ route('purchase-docs.show', $doc->original) }}" class="badge badge-primary">{{ __('Əsas mədaxil') }}: {{ $doc->original->doc_no }}</a>@endif
        @foreach ($returns as $r)<a href="{{ route('purchase-docs.show', $r->id) }}" @class(['badge', 'badge-warn' => ! $r->cancelled_at, 'line-through' => $r->cancelled_at])>{{ __('Qaytarma') }}: {{ $r->doc_no }}</a>@endforeach
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('purchase-docs.show', [$doc, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
            @if (! $doc->cancelled_at)
                @if ($doc->type === 'receipt')<a href="{{ route('purchase-docs.create', ['type' => 'return', 'from' => $doc->id]) }}" class="btn btn-sm"><x-icon name="returns" size="14"/> {{ __('Qaytarma yarat') }}</a>@endif
                <x-cancel-button :action="route('purchase-docs.destroy', $doc)" :confirm="__('Sənəd ləğv edilsin? Stok və təchizatçı borcu düzəldiləcək.')"/>
            @endif
        </div>
    </div>
    <x-cancelled-banner :doc="$doc"/>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('ƏDV-siz məbləğ')" :value="Fmt::number($doc->goods_amount, 2).' '.$cur"/>
        <x-kpi :label="__('ƏDV')" :value="Fmt::number($doc->vat_amount, 2).' '.$cur" :meta="$doc->vat_included ? __('qiymətlərə daxil idi') : __('qiymətlərin üstünə')"/>
        <x-kpi :label="$doc->type === 'receipt' ? __('Təchizatçıya borc') : __('Borcdan çıxılır')" :value="Fmt::number($doc->total_amount, 2).' '.$cur"/>
        @if ($doc->type === 'receipt')
            <x-kpi :label="__('Stok dəyəri (AZN)')" :value="Fmt::money($doc->total_cost_azn)" :meta="__('əlavə xərc').': '.Fmt::money($doc->customs_cost + $doc->transport_cost + $doc->other_cost)"/>
        @endif
    </div>
    @if ($doc->note)<div class="card px-4 py-3 text-sm"><span class="label">{{ __('Qeyd') }}</span>{{ $doc->note }}</div>@endif
    <section class="card overflow-hidden">
        <div class="tbl-wrap relative">
            <table class="tbl">
                <thead><tr><th>#</th><th>{{ __('Kod') }}</th><th>{{ __('Mal') }}</th><th class="num">{{ __('Miqdar') }}</th><th class="num">{{ __('Qiymət') }} ({{ $cur }})</th>
                    <th class="num">{{ __('ƏDV-siz') }}</th><th class="num">{{ __('ƏDV') }}</th>@if ($doc->type === 'receipt')<th class="num">{{ __('Əlavə xərc (₼)') }}</th><th class="num">{{ __('Vahid maya (₼)') }}</th>@endif</tr></thead>
                <tbody>
                    @foreach ($lines as $l)
                        <tr>
                            <td class="text-muted">{{ $l->row_no }}</td>
                            <td class="font-mono text-xs"><a href="{{ route('movements.product', $l->product_id) }}" class="text-primary-text hover:underline">{{ $l->product_code }}</a></td>
                            <td class="wrap">{{ $l->product_name }}</td>
                            <td class="num">{{ Fmt::qty($l->qty) }}</td>
                            <td class="num">{{ Fmt::number($l->price, 4) }}</td>
                            <td class="num">{{ Fmt::number($l->amount, 2) }}</td>
                            <td class="num">{{ Fmt::number($l->vat_amount, 2) }}<div class="text-xs text-muted">{{ rtrim(rtrim(number_format((float) $l->vat_rate, 2, ',', ''), '0'), ',') }} %</div></td>
                            @if ($doc->type === 'receipt')<td class="num">{{ Fmt::money($l->extra_cost_azn) }}</td><td class="num font-semibold">{{ Fmt::number($l->unit_cost_azn, 4) }}</td>@endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <p class="text-xs text-muted">{{ __('Yaradıb') }}: {{ $doc->creator?->name ?? '—' }} · {{ $doc->created_at?->format('d.m.Y H:i') }}</p>
    <x-audit-history type="PurchaseDocument" :id="$doc->id" :no="$doc->doc_no"/>
</x-layouts.app>
