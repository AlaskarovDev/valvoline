@php use App\Support\Fmt; $t = $transfer; $company = config('company'); @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Yük qaiməsi') }} № {{ $t->doc_no }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar"><button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button><button type="button" onclick="window.close()">{{ __('Bağla') }}</button></div>
<div class="sheet">
    <header><div><div class="company">{{ $company['name'] }}</div>@if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif</div></header>
    <h1>{{ __('Anbarlar arası yük qaiməsi') }} № {{ $t->doc_no }}</h1>
    <div class="doc-meta muted">{{ __('Tarix') }}: {{ Fmt::date($t->sent_date ?? $t->date) }} · {{ $reasons[$t->reason] ?? $t->reason }}@if ($t->workplace) · {{ __('İş yeri') }}: {{ $t->workplace->name }}@endif</div>
    <div class="parties">
        <div class="party"><div class="label">{{ __('Göndərən anbar') }}</div><div class="name">{{ $t->from->name }}</div>@if ($t->from->address)<div class="muted">{{ $t->from->address }}</div>@endif</div>
        <div class="party"><div class="label">{{ __('Qəbul edən anbar') }}</div><div class="name">{{ $t->to->name }}</div>@if ($t->to->address)<div class="muted">{{ $t->to->address }}</div>@endif</div>
    </div>
    @if ($t->driver || $t->vehicle_plate)<p>{{ __('Sürücü / məsul') }}: <strong>{{ $t->driver }}</strong> @if ($t->vehicle_plate) · {{ __('Maşın') }}: <strong>{{ $t->vehicle_plate }}</strong>@endif</p>@endif
    <table>
        <thead><tr><th style="width: 28px">№</th><th style="width: 90px">{{ __('Kod') }}</th><th>{{ __('Malın adı') }}</th><th class="num">{{ __('Göndərilən') }}</th><th class="num">{{ __('Qəbul edilən') }}</th><th class="num">{{ __('Vahid maya') }}</th><th class="num">{{ __('Dəyər') }}</th></tr></thead>
        <tbody>
            @foreach ($lines as $i => $l)
                <tr><td>{{ $i + 1 }}</td><td>{{ $l->product_code }}</td><td>{{ $l->product_name }}</td><td class="num">{{ Fmt::qty($l->qty) }}</td>
                    <td class="num">{{ $l->received_qty !== null ? Fmt::qty($l->received_qty) : '' }}</td><td class="num">{{ $l->moved_cost !== null ? Fmt::money($l->moved_cost) : '' }}</td><td class="num">{{ $l->moved_cost !== null ? Fmt::money($l->moved_cost * $l->qty) : '' }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><td colspan="3">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::qty($lines->sum('qty')) }}</td><td class="num">{{ Fmt::qty($lines->sum('received_qty')) }}</td><td></td><td class="num">{{ Fmt::money($lines->sum(fn ($l) => ($l->moved_cost ?? 0) * $l->qty)) }}</td></tr></tfoot>
    </table>
    @if ($t->note)<div class="note"><strong>{{ __('Qeyd') }}:</strong> {{ $t->note }}</div>@endif
    <div class="signs signs-3">
        <div>{{ __('Təhvil verdi') }} <span class="muted">({{ __('imza') }})</span></div>
        <div>{{ __('Sürücü') }} <span class="muted">({{ __('imza') }})</span></div>
        <div>{{ __('Təhvil aldı') }} <span class="muted">({{ __('imza') }})</span></div>
    </div>
</div>
</body>
</html>
