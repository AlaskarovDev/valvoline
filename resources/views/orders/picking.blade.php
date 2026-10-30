@php use App\Support\Fmt; $company = config('company'); @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Yığım vərəqi') }}</title>
    @include('documents._print-style')
</head>
<body>
<div class="toolbar"><button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button><button type="button" onclick="window.close()">{{ __('Bağla') }}</button></div>
<div class="sheet">
    <header>
        <div><div class="company">{{ $company['name'] }}</div><div class="muted">{{ __('Anbar') }}: {{ $warehouses ?: '—' }}</div></div>
        <div style="text-align: right" class="muted">{{ now()->format('d.m.Y H:i') }}</div>
    </header>
    <h1>{{ __('Yığım vərəqi') }}</h1>
    <p class="muted" style="text-align: center">{{ __(':n açıq sifariş', ['n' => $orders->count()]) }}: {{ $orders->map(fn ($o) => $o->order_no.' ('.$o->customer_name.')')->implode(', ') }}</p>
    <table>
        <thead><tr><th style="width: 24px">✓</th><th style="width: 90px">{{ __('Kod') }}</th><th>{{ __('Malın adı') }}</th><th class="num">{{ __('Cəmi') }}</th><th>{{ __('Sifarişlər üzrə') }}</th><th>{{ __('İlk götürülən partiya (FEFO)') }}</th></tr></thead>
        <tbody>
            @forelse ($rows as $r)
                <tr><td>☐</td><td>{{ $r->code }}</td><td>{{ $r->name }}</td><td class="num"><strong>{{ Fmt::qty($r->qty) }}</strong> {{ $r->unit }}</td><td class="muted">{{ $r->split }}</td><td>{{ $r->batches ?: '—' }}</td></tr>
            @empty
                <tr><td colspan="6" class="muted" style="text-align: center">{{ __('Açıq sifariş yoxdur.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="signs"><div>{{ __('Yığdı') }} <span class="muted">({{ __('imza') }})</span></div><div>{{ __('Yoxladı') }} <span class="muted">({{ __('imza') }})</span></div></div>
</div>
</body>
</html>
