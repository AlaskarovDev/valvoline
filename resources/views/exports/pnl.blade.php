@php use App\Support\Fmt; @endphp
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 20px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5px; color: #0f1b2d; }
        h1 { font-size: 13px; margin: 0 0 2px; color: #1f3a5f; }
        .meta { color: #5a687b; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #e9eff7; color: #1f3a5f; padding: 4px 4px; border-bottom: 1px solid #cbd3de; text-align: right; }
        th:first-child, td:first-child { text-align: left; }
        td { padding: 3px 4px; border-bottom: 1px solid #e2e7ee; text-align: right; white-space: nowrap; }
        .subtotal td { font-weight: bold; }
        .total td { font-weight: bold; border-top: 1.5px solid #1f3a5f; }
        .muted td { color: #5a687b; }
        .child td { color: #5a687b; font-size: 7px; }
        .child td:first-child { padding-left: 14px; }
    </style>
</head>
<body>
<h1>Mənfəət-zərər (P&amp;L)</h1>
<div class="meta">{{ $filterLabel }} · Hazırlanıb: {{ now()->format('d.m.Y H:i') }} · AZN</div>
<table>
    <thead><tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($lines as $line)
        <tr class="{{ $line['style'] }}">
            <td>{{ $line['label'] }}</td>
            @foreach ($columns as $c)
                <td>{{ $line['type'] === 'pct' ? Fmt::pct($line['values'][$c] ?? 0) : Fmt::money($line['values'][$c] ?? 0, 0, false) }}</td>
            @endforeach
            <td>{{ $line['type'] === 'pct' ? Fmt::pct($line['total']) : Fmt::money($line['total'], 0, false) }}</td>
        </tr>
        @foreach ($line['children'] as $child)
            <tr class="child">
                <td>{{ $child['label'] }}</td>
                @foreach ($columns as $c)<td>{{ Fmt::money($child['values'][$c] ?? 0, 0, false) }}</td>@endforeach
                <td>{{ Fmt::money(array_sum($child['values']), 0, false) }}</td>
            </tr>
        @endforeach
    @endforeach
    </tbody>
</table>
</body>
</html>
