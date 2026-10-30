@php $company = config('company'); @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $types[$doc->type] }} № {{ $doc->doc_no }}</title>
    @include('documents._print-style')
    <style>dl { display: grid; grid-template-columns: 190px 1fr; gap: 6px 16px; font-size: 13px; } dt { color: #555; } dd { margin: 0; }</style>
</head>
<body>
<div class="toolbar"><button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button><button type="button" onclick="window.close()">{{ __('Bağla') }}</button></div>
<div class="sheet">
@if ($doc->cancelled_at ?? null)<div class="cancelled-stamp">{{ __('LƏĞV EDİLİB') }} · {{ $doc->cancelled_at->format('d.m.Y') }}</div>@endif

    <header><div><div class="company">{{ $company['name'] }}</div>@if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif</div></header>
    <h1>{{ $types[$doc->type] }} № {{ $doc->doc_no }}</h1>
    <div style="margin-top: 16px">@include('finance._details')</div>
    <div class="signs">
        <div>{{ $doc->type === 'cash_receipt' ? __('Ödəyən') : __('Təhvil verdi') }} <span class="muted">({{ __('imza') }})</span></div>
        <div>{{ $doc->type === 'cash_receipt' ? __('Kassir / qəbul edən') : __('Təhvil aldı') }} <span class="muted">({{ __('imza') }})</span></div>
    </div>
</div>
</body>
</html>
