@php use App\Support\Fmt; $company = config('company'); @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $types[$doc->type] }} № {{ $doc->doc_no }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar"><button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button><button type="button" onclick="window.close()">{{ __('Bağla') }}</button></div>
<div class="sheet">
@if ($doc->cancelled_at ?? null)<div class="cancelled-stamp">{{ __('LƏĞV EDİLİB') }} · {{ $doc->cancelled_at->format('d.m.Y') }}</div>@endif

    <header><div><div class="company">{{ $company['name'] }}</div>@if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif</div></header>
    <h1>{{ $types[$doc->type] }} № {{ $doc->doc_no }}</h1>
    <div class="doc-meta muted">{{ __('Tarix') }}: {{ Fmt::date($doc->date) }} · {{ __('Anbar') }}: {{ $doc->warehouse->name }}@if ($doc->workplace) · {{ __('İş yeri') }}: {{ $doc->workplace->name }}@endif @if ($doc->purpose) · {{ __('Məqsəd') }}: {{ $doc->purpose }}@endif</div>
    @include('stockdocs._lines', ['print' => true])
    @if ($doc->note)<div class="note"><strong>{{ __('Qeyd') }}:</strong> {{ $doc->note }}</div>@endif
    <div class="signs signs-3">
        <div>{{ __('Anbardar') }} <span class="muted">({{ __('imza') }})</span></div>
        <div>{{ \App\Models\StockDocument::isCount($doc->type) ? __('Sayım komissiyası') : __('Məsul şəxs') }} <span class="muted">({{ __('imza') }})</span></div>
        <div>{{ __('Mühasib') }} <span class="muted">({{ __('imza') }})</span></div>
    </div>
</div>
</body>
</html>
