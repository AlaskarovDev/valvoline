@php use App\Support\Fmt; $company = config('company'); @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Qaytarma qaiməsi') }} № {{ $doc->doc_no }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar"><button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button><button type="button" onclick="window.close()">{{ __('Bağla') }}</button></div>
<div class="sheet">
@if ($doc->cancelled_at)<div class="cancelled-stamp">{{ __('LƏĞV EDİLİB') }} · {{ $doc->cancelled_at->format('d.m.Y') }}</div>@endif
    <header><div><div class="company">{{ $company['name'] }}</div>@if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif</div>
        <div style="text-align: right"><div class="muted">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</div></div></header>
    <h1>{{ __('Qaytarma qaiməsi') }} № {{ $doc->doc_no }}</h1>
    <p class="muted" style="text-align: center">{{ __('Tarix') }}: {{ Fmt::date($doc->date) }}@if ($doc->invoice) · {{ __('Faktura') }}: {{ $doc->invoice->doc_no }}@endif · {{ __('Səbəb') }}: {{ $reasons[$doc->reason] ?? $doc->reason }}</p>
    <div class="parties">
        <div class="party"><div class="label">{{ __('Qaytaran (müştəri)') }}</div><div class="name">{{ $doc->customer->name }}</div><div class="muted">{{ __('Kod') }}: {{ $doc->customer->code }}@if ($doc->customer->voen) · {{ __('VÖEN') }}: {{ $doc->customer->voen }}@endif</div></div>
        <div class="party"><div class="label">{{ __('Qəbul edən') }}</div><div class="name">{{ $company['name'] }}</div></div>
    </div>
    <table>
        <thead><tr><th style="width: 28px">№</th><th style="width: 90px">{{ __('Kod') }}</th><th>{{ __('Malın adı') }}</th><th class="num">{{ __('Miqdar') }}</th><th class="num">{{ __('Qiymət') }}</th><th class="num">{{ __('ƏDV') }}</th><th class="num">{{ __('Məbləğ') }}</th></tr></thead>
        <tbody>@foreach ($lines as $l)<tr><td>{{ $l->row_no }}</td><td>{{ $l->product_code }}</td><td>{{ $l->product_name }}</td><td class="num">{{ Fmt::qty($l->qty) }}</td><td class="num">{{ Fmt::money($l->price) }}</td><td class="num">{{ Fmt::money($l->vat_amount) }}</td><td class="num">{{ Fmt::money($l->net_amount) }}</td></tr>@endforeach</tbody>
    </table>
    <table class="totals">
        <tr><td>{{ __('ƏDV-siz məbləğ') }}</td><td class="num">{{ Fmt::money($doc->total_amount - $doc->vat_amount) }}</td></tr>
        <tr><td>{{ __('ƏDV cəmi') }}</td><td class="num">{{ Fmt::money($doc->vat_amount) }}</td></tr>
        <tr class="grand"><td>{{ __('Müştəri borcundan çıxılır') }}</td><td class="num">{{ Fmt::money($doc->total_amount) }}</td></tr>
    </table>
    <div class="signs"><div>{{ __('Təhvil verdi') }} <span class="muted">({{ __('imza') }})</span></div><div>{{ __('Qəbul etdi') }} <span class="muted">({{ __('imza') }})</span></div></div>
</div>
</body>
</html>
