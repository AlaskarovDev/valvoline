@php use App\Support\Barcode; use App\Support\Fmt; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Barkod etiketləri') }}</title>
    <style>
        @page { size: A4; margin: 8mm; }
        body { margin: 0; background: #eef0f3; font: 11px/1.3 "Segoe UI", Arial, sans-serif; color: #111; }
        .toolbar { display: flex; justify-content: center; gap: 8px; padding: 12px; }
        .toolbar button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #c8ccd2; background: #fff; cursor: pointer; }
        .toolbar button.primary { background: #1d3b6a; border-color: #1d3b6a; color: #fff; }
        .sheet { width: 194mm; margin: 0 auto 16px; padding: 4mm; background: #fff; display: grid; grid-template-columns: repeat(3, 1fr); gap: 3mm; }
        .label { border: 1px dashed #aab; border-radius: 2mm; padding: 2.5mm; height: 34mm; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; break-inside: avoid; }
        .name { font-weight: 700; font-size: 10.5px; max-height: 2.6em; overflow: hidden; }
        .code { color: #444; }
        .bc svg { width: 100%; height: 13mm; }
        .bottom { display: flex; justify-content: space-between; align-items: baseline; }
        .price { font-size: 15px; font-weight: 800; }
        .empty { padding: 24px; text-align: center; }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; padding: 0; width: auto; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" class="primary" onclick="window.print()">{{ __('Çap et') }}</button>
    <button type="button" onclick="window.close()">{{ __('Bağla') }}</button>
</div>
@if ($products->isEmpty())
    <p class="empty">{{ __('Məhsul seçilməyib.') }}</p>
@else
    <div class="sheet">
        @foreach ($products as $p)
            @for ($i = 0; $i < $copies; $i++)
                <div class="label">
                    <div class="name">{{ $p->name }}</div>
                    <div class="bc">{!! Barcode::svg($p->barcode ?: $p->code) !!}</div>
                    <div class="bottom">
                        <span class="code">{{ $p->barcode ?: $p->code }}</span>
                        @isset($prices[$p->id])<span class="price">{{ Fmt::money($prices[$p->id]['price']) }}</span>@endisset
                    </div>
                </div>
            @endfor
        @endforeach
    </div>
@endif
</body>
</html>
