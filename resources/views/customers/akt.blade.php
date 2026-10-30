@php use App\Support\Fmt; $company = config('company'); $s = $statement; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Üzləşmə aktı') }} — {{ $customer->name }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar">
    <form method="GET" style="display: flex; gap: 8px; align-items: center">
        <label>{{ __('Dövr') }} <input type="date" name="from" value="{{ $from }}"></label> — <input type="date" name="to" value="{{ $to }}" aria-label="{{ __('Son tarix') }}">
        <button type="submit">{{ __('Yenilə') }}</button>
    </form>
    <button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button>
</div>
<div class="sheet">
    <header>
        <div><div class="company">{{ $company['name'] }}</div>@if ($company['voen'])<div class="muted">{{ __('VÖEN') }}: {{ $company['voen'] }}</div>@endif</div>
        <div style="text-align: right" class="muted">{{ now()->format('d.m.Y') }}</div>
    </header>
    <h1>{{ __('Qarşılıqlı hesablaşmaların üzləşmə aktı') }}</h1>
    <p class="muted" style="text-align: center">{{ Fmt::date($from) }} — {{ Fmt::date($to) }}</p>
    <div class="parties">
        <div class="party"><div class="label">{{ __('Satıcı') }}</div><div class="name">{{ $company['name'] }}</div></div>
        <div class="party"><div class="label">{{ __('Alıcı') }}</div><div class="name">{{ $customer->name }}</div><div class="muted">{{ __('Kod') }}: {{ $customer->code }}@if ($customer->voen) · {{ __('VÖEN') }}: {{ $customer->voen }}@endif</div></div>
    </div>
    <table>
        <thead><tr><th style="width: 80px">{{ __('Tarix') }}</th><th>{{ __('Əməliyyat') }}</th><th>{{ __('Sənəd') }}</th><th class="num">{{ __('Debet (borc)') }}</th><th class="num">{{ __('Kredit (ödəniş)') }}</th><th class="num">{{ __('Qalıq') }}</th></tr></thead>
        <tbody>
            <tr><td colspan="5"><strong>{{ __(':date tarixinə qalıq', ['date' => Fmt::date($from)]) }}</strong></td><td class="num"><strong>{{ Fmt::money($s['opening']) }}</strong></td></tr>
            @foreach ($s['rows'] as $r)
                <tr><td>{{ Fmt::date($r['date']) }}</td><td>{{ __($r['type']) }}@if ($r['description']) <span class="muted">— {{ $r['description'] }}</span>@endif</td><td>{{ $r['doc'] }}</td>
                    <td class="num">{{ $r['debit'] ? Fmt::money($r['debit']) : '' }}</td><td class="num">{{ $r['credit'] ? Fmt::money($r['credit']) : '' }}</td><td class="num">{{ Fmt::money($r['balance']) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3">{{ __('Dövriyyə') }}</td><td class="num">{{ Fmt::money(array_sum(array_column($s['rows'], 'debit'))) }}</td><td class="num">{{ Fmt::money(array_sum(array_column($s['rows'], 'credit'))) }}</td><td></td></tr>
            <tr><td colspan="5">{{ __(':date tarixinə qalıq', ['date' => Fmt::date($to)]) }}</td><td class="num">{{ Fmt::money($s['closing']) }}</td></tr>
        </tfoot>
    </table>
    <p style="margin-top: 14px">
        @if ($s['closing'] > 0.005)
            {{ __(':date tarixinə borc: :customer → :company, :amount.', ['date' => Fmt::date($to), 'customer' => $customer->name, 'company' => $company['name'], 'amount' => Fmt::money($s['closing'])]) }}
        @elseif ($s['closing'] < -0.005)
            {{ __(':date tarixinə borc (avans): :company → :customer, :amount.', ['date' => Fmt::date($to), 'customer' => $customer->name, 'company' => $company['name'], 'amount' => Fmt::money(-$s['closing'])]) }}
        @else
            {{ __(':date tarixinə tərəflərin bir-birinə borcu yoxdur.', ['date' => Fmt::date($to)]) }}
        @endif
    </p>
    <div class="signs"><div>{{ $company['name'] }} <span class="muted">({{ __('imza, möhür') }})</span></div><div>{{ $customer->name }} <span class="muted">({{ __('imza, möhür') }})</span></div></div>
</div>
</body>
</html>
