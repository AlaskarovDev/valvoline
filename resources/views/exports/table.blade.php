<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 20px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #0f1b2d; }
        h1 { font-size: 13px; margin: 0 0 2px; color: #1f3a5f; }
        .meta { color: #5a687b; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #e9eff7; color: #1f3a5f; text-align: left; padding: 4px 5px; border-bottom: 1px solid #cbd3de; font-size: 8px; }
        td { padding: 3px 5px; border-bottom: 1px solid #e2e7ee; }
        .num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; background: #f3f5f8; }
    </style>
</head>
<body>
<h1>{{ $title }}</h1>
<div class="meta">{{ $filterLabel }} · Hazırlanıb: {{ now()->format('d.m.Y H:i') }} · {{ count($rows) }} sətir</div>
<table>
    <thead>
    <tr>
        @foreach ($columns as $column)
            <th @class(['num' => in_array($column['type'], ['money', 'qty', 'int', 'pct'], true)])>{{ $column['label'] }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            @foreach ($columns as $column)
                <td @class(['num' => in_array($column['type'], ['money', 'qty', 'int', 'pct'], true)])>{{ strip_tags($table->display($row, $column)) }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
    @if ($footer)
        <tfoot>
        <tr>
            @foreach ($columns as $i => $column)
                <td @class(['num' => in_array($column['type'], ['money', 'qty', 'int', 'pct'], true)])>
                    @if (isset($footer[$column['key']]))
                        {{ \App\Reports\Table::formatValue($footer[$column['key']], $column['type']) }}
                    @elseif ($i === 0)
                        Cəmi
                    @endif
                </td>
            @endforeach
        </tr>
        </tfoot>
    @endif
</table>
</body>
</html>
