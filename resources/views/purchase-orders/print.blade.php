@php use App\Support\Fmt; $company = config('company'); $cur = $doc->currency; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Alış sifarişi') }} № {{ $doc->doc_no }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar"><button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button><button type="button" onclick="window.close()">{{ __('Bağla') }}</button></div>
<div class="sheet">
@if ($doc->cancelled_at)<div class="cancelled-stamp">{{ __('LƏĞV EDİLİB') }} · {{ $doc->cancelled_at->format('d.m.Y') }}</div>@endif
    <header>
        <div><div class="company">{{ $company['name'] }}</div>@if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif</div>
        <div style="text-align: right" class="muted">{{ __('Çatdırılma anbarı') }}: {{ $doc->warehouse->name }}</div>
    </header>
    <h1>{{ __('Alış sifarişi') }} № {{ $doc->doc_no }}</h1>
    <p class="muted" style="text-align: center">{{ __('Tarix') }}: {{ Fmt::date($doc->date) }}@if ($doc->expected_date) · {{ __('Gözlənilən tarix') }}: {{ Fmt::date($doc->expected_date) }}@endif</p>
    <div class="parties">
        <div class="party"><div class="label">{{ __('Təchizatçı') }}</div><div class="name">{{ $doc->supplier->name }}</div>@if ($doc->supplier->voen)<div class="muted">{{ __('VÖEN') }}: {{ $doc->supplier->voen }}</div>@endif</div>
        <div class="party"><div class="label">{{ __('Sifarişçi') }}</div><div class="name">{{ $company['name'] }}</div></div>
    </div>
    <table>
        <thead><tr><th style="width: 28px">№</th><th style="width: 90px">{{ __('Kod') }}</th><th>{{ __('Malın adı') }}</th><th class="num">{{ __('Miqdar') }}</th><th class="num">{{ __('Qiymət') }} ({{ $cur }})</th><th class="num">{{ __('Məbləğ') }}</th></tr></thead>
        <tbody>
            @foreach ($lines as $l)
                <tr><td>{{ $l->row_no }}</td><td>{{ $l->product_code }}</td><td>{{ $l->product_name }}</td><td class="num">{{ Fmt::qty($l->qty) }}</td><td class="num">{{ Fmt::number($l->price, 4) }}</td><td class="num">{{ Fmt::number($l->amount, 2) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><td colspan="5">{{ __('Cəmi (ƏDV-siz)') }}</td><td class="num">{{ Fmt::number($doc->total_amount, 2) }} {{ $cur }}</td></tr></tfoot>
    </table>
    @if ($doc->note)<p style="margin-top: 12px">{{ $doc->note }}</p>@endif
    <div class="signs"><div>{{ __('Sifarişi verdi') }} <span class="muted">({{ __('imza') }})</span></div><div>{{ __('Təsdiq edən') }} <span class="muted">({{ __('imza, möhür') }})</span></div></div>
</div>
</body>
</html>
