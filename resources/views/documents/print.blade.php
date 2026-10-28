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
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f3; color: #111; font: 12px/1.45 "Segoe UI", Arial, sans-serif; }
        .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 14mm 12mm; background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .12); }
        .toolbar { display: flex; justify-content: center; gap: 8px; padding: 12px; }
        .toolbar button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #c8ccd2; background: #fff; cursor: pointer; }
        .toolbar button.primary { background: #1d3b6a; border-color: #1d3b6a; color: #fff; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 10px; }
        header .company { font-size: 16px; font-weight: 700; }
        header .muted, .muted { color: #555; }
        h1 { margin: 18px 0 2px; font-size: 20px; text-align: center; letter-spacing: .02em; }
        .doc-meta { text-align: center; margin-bottom: 16px; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px; }
        .party { border: 1px solid #c8ccd2; border-radius: 6px; padding: 8px 10px; }
        .party .label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #555; margin-bottom: 2px; }
        .party .name { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9aa0a8; padding: 5px 6px; vertical-align: top; }
        th { background: #f1f3f5; font-size: 11px; text-align: left; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .sub { font-size: 10.5px; color: #444; margin-top: 2px; }
        tfoot td { font-weight: 700; background: #f8f9fa; }
        .totals { margin-left: auto; margin-top: 10px; width: 280px; }
        .totals td { border: none; padding: 2px 0; }
        .totals tr.grand td { border-top: 2px solid #111; padding-top: 4px; font-size: 14px; font-weight: 700; }
        .note { margin-top: 12px; }
        .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 46px; }
        .signs div { border-top: 1px solid #111; padding-top: 4px; font-size: 11px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        }
    </style>
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
        {{ __('Tarix') }}: {{ Fmt::date($doc->date) }}
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
