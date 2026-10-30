@php use App\Support\Fmt; $company = config('company'); $cur = $doc->currency; $heading = \App\Models\PurchaseDocument::typeLabels()[$doc->type]; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} № {{ $doc->doc_no }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar"><button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button><button type="button" onclick="window.close()">{{ __('Bağla') }}</button></div>
<div class="sheet">
@if ($doc->cancelled_at)<div class="cancelled-stamp">{{ __('LƏĞV EDİLİB') }} · {{ $doc->cancelled_at->format('d.m.Y') }}</div>@endif
    <header>
        <div><div class="company">{{ $company['name'] }}</div>@if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif</div>
        <div style="text-align: right"><div class="muted">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</div></div>
    </header>
    <h1>{{ $heading }} № {{ $doc->doc_no }}</h1>
    <p class="muted" style="text-align: center">{{ __('Tarix') }}: {{ Fmt::date($doc->date) }}@if ($doc->supplier_invoice_no) · {{ __('Təchizatçı qaiməsi') }}: {{ $doc->supplier_invoice_no }}@endif @if ($cur !== 'AZN') · 1 {{ $cur }} = {{ rtrim(rtrim(number_format($doc->rate, 6, ',', ' '), '0'), ',') }} ₼@endif</p>
    <div class="parties">
        <div class="party"><div class="label">{{ __('Təchizatçı') }}</div><div class="name">{{ $doc->supplier->name }}</div><div class="muted">{{ __('Kod') }}: {{ $doc->supplier->code }}@if ($doc->supplier->voen) · {{ __('VÖEN') }}: {{ $doc->supplier->voen }}@endif</div></div>
        <div class="party"><div class="label">{{ __('Alıcı') }}</div><div class="name">{{ $company['name'] }}</div></div>
    </div>
    <table>
        <thead><tr><th style="width: 28px">№</th><th style="width: 90px">{{ __('Kod') }}</th><th>{{ __('Malın adı') }}</th><th class="num">{{ __('Miqdar') }}</th><th class="num">{{ __('Qiymət') }}</th><th class="num">{{ __('ƏDV-siz') }}</th><th class="num">{{ __('ƏDV') }}</th></tr></thead>
        <tbody>
            @foreach ($lines as $l)
                <tr><td>{{ $l->row_no }}</td><td>{{ $l->product_code }}</td><td>{{ $l->product_name }}</td><td class="num">{{ Fmt::qty($l->qty) }}</td><td class="num">{{ Fmt::number($l->price, 4) }}</td><td class="num">{{ Fmt::number($l->amount, 2) }}</td><td class="num">{{ Fmt::number($l->vat_amount, 2) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>{{ __('ƏDV-siz məbləğ') }}</td><td class="num">{{ Fmt::number($doc->goods_amount, 2) }} {{ $cur }}</td></tr>
        <tr><td>{{ __('ƏDV cəmi') }}</td><td class="num">{{ Fmt::number($doc->vat_amount, 2) }} {{ $cur }}</td></tr>
        <tr class="grand"><td>{{ __('Yekun') }}</td><td class="num">{{ Fmt::number($doc->total_amount, 2) }} {{ $cur }}</td></tr>
    </table>
    @if ($doc->note)<div class="note"><strong>{{ __('Qeyd') }}:</strong> {{ $doc->note }}</div>@endif
    <div class="signs"><div>{{ $doc->type === 'receipt' ? __('Təhvil verdi') : __('Qəbul etdi') }} <span class="muted">({{ __('imza') }})</span></div><div>{{ $doc->type === 'receipt' ? __('Qəbul etdi (anbardar)') : __('Təhvil verdi (anbardar)') }} <span class="muted">({{ __('imza') }})</span></div></div>
</div>
</body>
</html>
