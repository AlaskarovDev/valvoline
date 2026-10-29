@php use App\Support\Fmt; $company = config('company'); @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Silinmə aktı') }} № {{ $doc->doc_no }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar"><button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button><button type="button" onclick="window.close()">{{ __('Bağla') }}</button></div>
<div class="sheet">
    <header><div><div class="company">{{ $company['name'] }}</div>@if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif</div></header>
    <h1>{{ __('Malın silinməsi aktı') }} № {{ $doc->doc_no }}</h1>
    <div class="doc-meta muted">{{ __('Tarix') }}: {{ Fmt::date($doc->date) }} · {{ __('Anbar') }}: {{ $doc->warehouse->name }} · {{ __('Səbəb') }}: {{ $reasons[$doc->reason] ?? $doc->reason }}@if ($doc->workplace) · {{ __('İş yeri') }}: {{ $doc->workplace->name }}@endif</div>
    @include('writeoffs._lines', ['print' => true])
    @if ($doc->note)<div class="note"><strong>{{ __('Qeyd') }}:</strong> {{ $doc->note }}</div>@endif
    <p style="margin-top: 16px">{{ __('Komissiya yuxarıda göstərilən malların istifadəyə yararsız olduğunu təsdiq edir və onların silinməsini qərara alır.') }}</p>
    <div class="signs signs-3">
        <div>{{ __('Komissiya sədri') }} <span class="muted">({{ __('imza') }})</span></div>
        <div>{{ __('Anbardar') }} <span class="muted">({{ __('imza') }})</span></div>
        <div>{{ __('Mühasib') }} <span class="muted">({{ __('imza') }})</span></div>
    </div>
</div>
</body>
</html>
