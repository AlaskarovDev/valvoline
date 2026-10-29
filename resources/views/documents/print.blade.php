@php
    use App\Support\Fmt;
    $company = config('company');
    $heading = ['order' => __('Sifariş'), 'delivery' => __('Təhvil-təslim aktı'), 'invoice' => __('Satış fakturası')][$kind];
    $types = ['sale' => '', 'gift' => __('hədiyyə'), 'internal' => __('daxili istifadə')];
    $showDiscount = $kind !== 'delivery';
    $customer = $doc->customer;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} № {{ $doc->doc_no }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar">
    <button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button>
    <button type="button" onclick="window.close()">{{ __('Bağla') }}</button>
</div>
<div class="sheet">
    <header>
        <div>
            <div class="company">{{ $company['name'] }}</div>
            @if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif
            @if ($company['address'])<div class="muted">{{ $company['address'] }}</div>@endif
            @if ($company['phone'])<div class="muted">{{ $company['phone'] }}</div>@endif
        </div>
        <div style="text-align: right">
            @if ($company['bank'])<div class="muted">{{ $company['bank'] }}</div>@endif
            @if ($company['iban'])<div class="muted">IBAN: {{ $company['iban'] }}</div>@endif
        </div>
    </header>

    <h1>{{ $heading }} № {{ $doc->doc_no }}</h1>
    <div class="doc-meta muted">
        {{ __('Tarix') }}: {{ Fmt::date($doc->date) }}@if ($doc->workplace) · {{ __('İş yeri') }}: {{ $doc->workplace->name }}@endif
        @if ($kind !== 'order' && $doc->order) · {{ __('Sifariş') }} № {{ $doc->order->order_no }}@endif
        @if ($kind === 'invoice' && $doc->deliveryNote) · {{ __('Təhvil-təslim') }} № {{ $doc->deliveryNote->doc_no }}@endif
    </div>

    <div class="parties">
        <div class="party">
            <div class="label">{{ $kind === 'delivery' ? __('Təhvil verən') : __('Satıcı') }}</div>
            <div class="name">{{ $company['name'] }}</div>
            @if ($doc->warehouse)<div class="muted">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</div>@endif
            @if ($doc->manager)<div class="muted">{{ __('Satış nümayəndəsi') }}: {{ $doc->manager->name }}</div>@endif
        </div>
        <div class="party">
            <div class="label">{{ $kind === 'delivery' ? __('Təhvil alan') : __('Alıcı') }}</div>
            <div class="name">{{ $customer->name ?? $doc->customer_name }}</div>
            <div class="muted">{{ __('Kod') }}: {{ $customer->code ?? $doc->customer_code }}@if ($customer?->voen) · {{ __('VÖEN') }}: {{ $customer->voen }}@endif</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 28px">№</th>
                <th style="width: 90px">{{ __('Kod') }}</th>
                <th>{{ __('Malın adı') }}</th>
                <th class="num" style="width: 60px">{{ __('Miqdar') }}</th>
                <th class="num" style="width: 80px">{{ __('Qiymət') }}</th>
                @if ($showDiscount)<th class="num" style="width: 80px">{{ __('Endirim') }}</th>@endif
                <th class="num" style="width: 92px">{{ __('Məbləğ') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $l)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $l->product_code }}</td>
                    <td>
                        {{ $l->product_name }}@if ($types[$l->op_type] ?? '') <span class="muted">({{ $types[$l->op_type] }})</span>@endif
                        @foreach ($campaigns[$l->sales_order_item_id ?? 0] ?? [] as $c)
                            <div class="sub">↳ {{ __('Kampaniya') }} {{ $c->campaign_code }} — {{ $c->campaign_name }}: −{{ Fmt::money($c->amount) }}</div>
                        @endforeach
                    </td>
                    <td class="num">{{ Fmt::qty($l->qty) }}</td>
                    <td class="num">{{ Fmt::money($l->price) }}</td>
                    @if ($showDiscount)<td class="num">{{ isset($l->amount) && (float) $l->amount - (float) $l->net_amount > 0.004 ? Fmt::money((float) $l->amount - (float) $l->net_amount) : '—' }}</td>@endif
                    <td class="num">{{ Fmt::money($l->net_amount) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">{{ __('Cəmi') }}</td>
                <td class="num">{{ Fmt::qty($totals['qty']) }}</td>
                <td></td>
                @if ($showDiscount)<td class="num">{{ Fmt::money($totals['discount'] ?? 0) }}</td>@endif
                <td class="num">{{ Fmt::money($totals['amount']) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($showDiscount)
        <table class="totals">
            <tr><td>{{ __('Məbləğ (endirimsiz)') }}</td><td class="num">{{ Fmt::money($totals['gross'] ?? 0) }}</td></tr>
            <tr><td>{{ __('Endirim') }}</td><td class="num">−{{ Fmt::money($totals['discount'] ?? 0) }}</td></tr>
            <tr class="grand"><td>{{ __('Ödəniləcək məbləğ') }}</td><td class="num">{{ Fmt::money($totals['amount']) }}</td></tr>
        </table>
    @endif

    @if ($doc->note)<div class="note"><strong>{{ __('Qeyd') }}:</strong> {{ $doc->note }}</div>@endif

    <div class="signs">
        <div>{{ $kind === 'delivery' ? __('Təhvil verdi') : __('Satıcı') }} <span class="muted">({{ __('imza') }})</span></div>
        <div>{{ $kind === 'delivery' ? __('Təhvil aldı') : __('Alıcı') }} <span class="muted">({{ __('imza') }})</span></div>
    </div>
</div>
</body>
</html>
