@php
    use App\Support\Fmt;
    $p = $product;
    $isAdmin = auth()->user()->isAdmin();
    $vatModes = ['standard' => __('ƏDV-yə cəlb edilən'), 'zero' => __('ƏDV 0 dərəcə'), 'exempt' => __('ƏDV-dən azad'), 'not_subject' => __('ƏDV-yə cəlb olunmayan')];
    $totalQty = $stock->sum('qty');
    $reserved = $stock->sum('reserved');
    $cost = $p->current_avg_cost !== null ? (float) $p->current_avg_cost : ($purchase['price'] ?? null);
    $margin = $sale && $cost !== null && $sale['price'] > 0 ? ($sale['price'] - $cost) / $sale['price'] : null;
    $todayStr = $today->toDateString();
@endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('products.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        @unless ($p->is_active)<span class="badge badge-warn">{{ __('Passiv') }}</span>@endunless
        @if ($p->brand)<span class="badge">{{ $p->brand->name }}</span>@endif
        @if ($p->productClass)<span class="badge">{{ $p->productClass->name }}</span>@endif
        @if ($p->category)<span class="badge">{{ $p->category->name }}</span>@endif
        @if ($p->barcode)<span class="badge font-mono">{{ $p->barcode }}</span>@endif
        <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('movements.product', $p) }}" class="btn btn-sm"><x-icon name="movements" size="14"/> {{ __('Hərəkətlər') }}</a>
            <a href="{{ route('products.labels', ['ids' => [$p->id], 'qty' => 12]) }}" target="_blank" rel="noopener" class="btn btn-sm"><x-icon name="print" size="14"/> {{ __('Etiket') }}</a>
            @if ($isAdmin)
                <a href="{{ route('products.create', ['copy' => $p->id]) }}" class="btn btn-sm"><x-icon name="plus" size="14"/> {{ __('Kopyala') }}</a>
                <form method="POST" action="{{ route('products.toggle', $p) }}">@csrf<button class="btn btn-sm">{{ $p->is_active ? __('Passiv et') : __('Aktivləşdir') }}</button></form>
                <a href="{{ route('products.edit', $p) }}" class="btn btn-sm btn-primary"><x-icon name="edit" size="14"/> {{ __('Düzəliş et') }}</a>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-kpi :label="__('Real stok')" :value="Fmt::qty($totalQty).' '.($p->baseUnit->name ?? '')" :tone="$totalQty < 0 ? 'neg' : null"/>
        <x-kpi :label="__('Rezerv / sərbəst')" :value="Fmt::qty($reserved).' / '.Fmt::qty($totalQty - $reserved)"/>
        <x-kpi :label="__('Satış qiyməti')" :value="$sale ? Fmt::money($sale['price']) : '—'" :meta="$sale ? $sale['label'].' · '.__('ƏDV-siz').' '.Fmt::money($sale['net']) : null"/>
        <x-kpi :label="__('Maya / alış')" :value="$cost !== null ? Fmt::money($cost) : '—'" :meta="$purchase ? ($purchase['source'] === 'card' ? __('alış kartı') : __('son idxal')).' '.Fmt::money($purchase['price']) : null"/>
        <x-kpi :label="__('Marja')" :value="Fmt::pct($margin)" :tone="$margin !== null && $margin < 0 ? 'neg' : null"
               :meta="__('90 gün: :qty, :amount', ['qty' => Fmt::qty($sales->qty ?? 0), 'amount' => Fmt::money($sales->amount ?? 0)])"/>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Məhsul kartı') }}</h2></div>
            <div class="flex gap-4 p-4">
                @if ($p->image_path)<img src="{{ asset($p->image_path) }}" alt="{{ $p->name }}" class="size-28 shrink-0 rounded-lg border border-line object-contain">@endif
                <dl class="grid flex-1 grid-cols-[140px_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
                    <dt class="text-muted">{{ __('Kod') }}</dt><dd class="font-mono">{{ $p->code }}</dd>
                    @if ($p->short_name)<dt class="text-muted">{{ __('Qısa ad') }}</dt><dd>{{ $p->short_name }}</dd>@endif
                    <dt class="text-muted">{{ __('İstehsalçı') }}</dt><dd>{{ $p->manufacturer->name ?? '—' }}@if ($p->country_of_origin) · {{ $p->country_of_origin }}@endif</dd>
                    <dt class="text-muted">{{ __('Məhsul qrupu') }}</dt><dd>{{ $p->group->name ?? '—' }}@if ($p->series) · {{ $p->series }}@endif</dd>
                    @if ($p->viscosity || $p->specifications)<dt class="text-muted">{{ __('Spesifikasiya') }}</dt><dd>{{ collect([$p->viscosity, $p->specifications])->filter()->implode(' · ') }}</dd>@endif
                    @if ($p->application)<dt class="text-muted">{{ __('Tətbiq sahəsi') }}</dt><dd>{{ $p->application }}</dd>@endif
                    <dt class="text-muted">{{ __('Həcm / çəki') }}</dt><dd>{{ (float) $p->volume_l ? Fmt::number($p->volume_l, 3).' L' : '—' }}@if ($p->weight_kg) · {{ Fmt::number($p->weight_kg, 3) }} kq @endif</dd>
                    <dt class="text-muted">{{ __('ƏDV') }}</dt><dd>{{ $vatModes[$p->vat_mode] ?? $p->vat_mode }}@if ($p->vat_mode === 'standard') · {{ Fmt::number($p->vat_rate, 0) }} %@endif @if ($p->excise_rate) · {{ __('aksiz') }} {{ Fmt::number($p->excise_rate, 2) }} %@endif</dd>
                    <dt class="text-muted">{{ __('Malın kodu (XİF MN)') }}</dt><dd class="font-mono">{{ $p->hs_code ?? '—' }}</dd>
                    <dt class="text-muted">{{ __('Stok həddləri') }}</dt><dd>{{ __('min') }} {{ $p->min_stock_qty !== null ? Fmt::qty($p->min_stock_qty) : '—' }} · {{ __('max') }} {{ $p->max_stock_qty !== null ? Fmt::qty($p->max_stock_qty) : '—' }}</dd>
                    @if ($p->min_price || $p->max_discount_pct)<dt class="text-muted">{{ __('Qiymət nəzarəti') }}</dt><dd>@if ($p->min_price){{ __('min qiymət') }} {{ Fmt::money($p->min_price) }}@endif @if ($p->max_discount_pct) · {{ __('max endirim') }} {{ Fmt::number($p->max_discount_pct, 2) }} %@endif</dd>@endif
                    @if ($p->shelf_life_months)<dt class="text-muted">{{ __('Saxlanma müddəti') }}</dt><dd>{{ __(':n ay', ['n' => $p->shelf_life_months]) }}</dd>@endif
                    @if ($p->description)<dt class="text-muted">{{ __('Təsvir') }}</dt><dd class="whitespace-pre-line">{{ $p->description }}</dd>@endif
                </dl>
            </div>
        </section>

        <div class="flex flex-col gap-4">
            <section class="card overflow-hidden">
                <div class="card-head"><h2 class="card-title">{{ __('Anbarlar üzrə qalıq') }}</h2></div>
                <div class="tbl-wrap"><table class="tbl">
                    <thead><tr><th>{{ __('Anbar') }}</th><th class="num">{{ __('Real stok') }}</th><th class="num">{{ __('Rezerv') }}</th><th class="num">{{ __('Sərbəst') }}</th><th>{{ __('Son hərəkət') }}</th></tr></thead>
                    <tbody>
                        @forelse ($stock as $s)
                            <tr><td>{{ $s->name }}</td><td class="num font-semibold {{ $s->qty < 0 ? 'neg' : '' }}">{{ Fmt::qty($s->qty) }}</td><td class="num">{{ Fmt::qty($s->reserved) }}</td><td class="num">{{ Fmt::qty($s->available) }}</td><td>{{ Fmt::date($s->last_movement_date) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">{{ __('Stok hərəkəti yoxdur.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </section>
            <section class="card overflow-hidden">
                <div class="card-head"><h2 class="card-title">{{ __('Vahidlər və barkodlar') }}</h2></div>
                <div class="tbl-wrap"><table class="tbl">
                    <thead><tr><th>{{ __('Vahid') }}</th><th class="num">{{ __('Əmsal') }}</th><th>{{ __('Barkod') }}</th></tr></thead>
                    <tbody>
                        <tr><td>{{ $p->baseUnit->name ?? '—' }} <span class="badge">{{ __('əsas') }}</span></td><td class="num">1</td><td class="font-mono">{{ $p->barcode ?? '—' }}</td></tr>
                        @foreach ($p->units as $u)
                            <tr><td>{{ $u->unit->name }}@if ($u->is_sale_default) <span class="badge badge-primary">{{ __('satış') }}</span>@endif @if ($u->is_purchase_default) <span class="badge">{{ __('alış') }}</span>@endif</td>
                                <td class="num">{{ Fmt::number($u->factor, 2) }} {{ $p->baseUnit->name ?? '' }}</td>
                                <td class="font-mono">{{ $barcodes->where('unit_name', $u->unit->name)->pluck('barcode')->implode(', ') ?: '—' }}</td></tr>
                        @endforeach
                        @foreach ($barcodes->whereNull('unit_name') as $b)
                            <tr><td class="text-muted">{{ __('əlavə barkod') }}</td><td></td><td class="font-mono">{{ $b->barcode }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            </section>
            @if ($alternatives->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Alternativ məhsullar') }}</h2></div>
                    <ul class="flex flex-col gap-1 p-4 text-sm">
                        @foreach ($alternatives as $a)<li><a href="{{ route('products.show', $a->id) }}" class="text-primary-text hover:underline">{{ $a->code }} — {{ $a->name }}</a> <span class="text-xs text-muted">{{ __('qalıq') }} {{ Fmt::qty($a->stock_qty) }}</span></li>@endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>

    <section class="card overflow-hidden" x-data="{ add: false, type: 'sale', target: 'list' }">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ __('Qiymət kartları') }}</h2>
                <div class="card-sub">{{ __('Satış, alış və müştəriyə özəl qiymətlər tarixçə ilə. Yeni kart əvvəlki eyni kartı başlama tarixindən bir gün əvvəl bağlayır.') }}</div>
            </div>
            @if ($isAdmin)<button type="button" class="btn btn-sm btn-primary" @click="add = !add"><x-icon name="plus" size="14"/> {{ __('Qiymət əlavə et') }}</button>@endif
        </div>
        @if ($isAdmin)
            <form x-show="add" x-cloak method="POST" action="{{ route('prices.store') }}" class="grid grid-cols-1 gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-6">
                @csrf
                <input type="hidden" name="product_id" value="{{ $p->id }}">
                <div><label class="label" for="pc-type">{{ __('Növ') }}</label>
                    <select id="pc-type" name="type" class="select" x-model="type"><option value="sale">{{ __('Satış') }}</option><option value="purchase">{{ __('Alış') }}</option></select></div>
                <div><label class="label" for="pc-target">{{ __('Kimə') }}</label>
                    <select id="pc-target" class="select" x-model="target"><option value="list">{{ __('Qiymət növü') }}</option><option value="customer" x-show="type === 'sale'">{{ __('Müştəriyə özəl') }}</option></select></div>
                @foreach (['sale', 'purchase'] as $listType)
                    <div x-show="type === '{{ $listType }}' && (target === 'list' || type === 'purchase')"><label class="label" for="pc-list-{{ $listType }}">{{ __('Qiymət növü') }}</label>
                        <select id="pc-list-{{ $listType }}" name="price_list_id" class="select" :disabled="type !== '{{ $listType }}' || (target === 'customer' && type === 'sale')">
                            @foreach ($priceLists->where('type', $listType) as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                        </select></div>
                @endforeach
                <div x-show="target === 'customer' && type === 'sale'" class="sm:col-span-2"><label class="label" for="pc-customer">{{ __('Müştəri') }}</label>
                    <select id="pc-customer" name="customer_id" class="select" :disabled="!(target === 'customer' && type === 'sale')">
                        @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }} · {{ $c->code }}</option>@endforeach
                    </select></div>
                <div><label class="label" for="pc-unit">{{ __('Vahid') }}</label>
                    <select id="pc-unit" name="product_unit_id" class="select"><option value="">{{ $p->baseUnit->name ?? __('əsas') }}</option>
                        @foreach ($p->units as $u)<option value="{{ $u->id }}">{{ $u->unit->name }} ({{ Fmt::number($u->factor, 2) }})</option>@endforeach
                    </select></div>
                <div><label class="label" for="pc-price">{{ __('Qiymət') }}</label><input id="pc-price" type="number" step="0.0001" min="0.0001" name="price" required class="input"></div>
                <div><label class="label" for="pc-vat">{{ __('ƏDV') }}</label>
                    <select id="pc-vat" name="vat_included" class="select"><option value="1">{{ __('ƏDV daxil') }}</option><option value="0">{{ __('ƏDV xaric') }}</option></select></div>
                <div><label class="label" for="pc-minqty">{{ __('Min. miqdar') }}</label><input id="pc-minqty" type="number" step="any" min="0" name="min_qty" class="input" value="0" title="{{ __('Həcm qiyməti: bu miqdardan başlayaraq') }}"></div>
                <div><label class="label" for="pc-from">{{ __('Başlama tarixi') }}</label><input id="pc-from" type="date" name="valid_from" required class="input" value="{{ $todayStr }}"></div>
                <div><label class="label" for="pc-to">{{ __('Bitmə tarixi') }}</label><input id="pc-to" type="date" name="valid_to" class="input"></div>
                <div class="sm:col-span-2"><label class="label" for="pc-note">{{ __('Qeyd') }}</label><input id="pc-note" name="note" maxlength="255" class="input"></div>
                <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Yadda saxla') }}</button></div>
            </form>
        @endif
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>{{ __('Növ') }}</th><th>{{ __('Qiymət növü / müştəri') }}</th><th>{{ __('Vahid') }}</th><th class="num">{{ __('Qiymət') }}</th><th>{{ __('ƏDV') }}</th><th class="num">{{ __('Min. miqdar') }}</th><th>{{ __('Başlama') }}</th><th>{{ __('Bitmə') }}</th><th>{{ __('Vəziyyət') }}</th><th>{{ __('Kim') }}</th>@if ($isAdmin)<th></th>@endif</tr></thead>
                <tbody>
                    @forelse ($cards as $c)
                        @php
                            $from = (string) $c->valid_from;
                            $to = $c->valid_to ? (string) $c->valid_to : null;
                            $status = ! $c->is_active ? 'off' : ($from > $todayStr ? 'future' : ($to !== null && $to < $todayStr ? 'expired' : 'current'));
                        @endphp
                        <tr @class(['opacity-60' => in_array($status, ['off', 'expired'], true)])>
                            <td>{{ $c->type === 'sale' ? __('Satış') : __('Alış') }}</td>
                            <td class="wrap">@if ($c->customer_id)<span class="badge badge-primary">★ {{ $c->customer_name }}</span>@else{{ $c->list_name ?? '—' }}@endif</td>
                            <td>{{ $c->unit_name ? $c->unit_name.' ('.Fmt::number($c->factor, 2).')' : ($p->baseUnit->name ?? '') }}</td>
                            <td class="num font-semibold">{{ Fmt::money($c->price) }}</td>
                            <td>{{ $c->vat_included ? __('daxil') : __('xaric') }}</td>
                            <td class="num">{{ (float) $c->min_qty > 0 ? '≥ '.Fmt::qty($c->min_qty) : '—' }}</td>
                            <td>{{ Fmt::date($c->valid_from) }}</td>
                            <td>{{ $c->valid_to ? Fmt::date($c->valid_to) : '∞' }}</td>
                            <td><span @class(['badge', 'badge-primary' => $status === 'current', 'badge-warn' => $status === 'future'])>{{ ['current' => __('qüvvədə'), 'future' => __('gələcək'), 'expired' => __('bitib'), 'off' => __('dayandırılıb')][$status] }}</span></td>
                            <td class="text-xs text-muted">{{ $c->user_name ?? ($c->note ?? '') }}</td>
                            @if ($isAdmin)
                                <td class="whitespace-nowrap">
                                    @if ($status === 'current' || $status === 'future')
                                        <form method="POST" action="{{ route('prices.update', $c->id) }}" class="inline">@csrf @method('PUT')<input type="hidden" name="action" value="close"><button class="btn btn-sm" title="{{ __('Dünəndən bağla') }}">{{ __('Bağla') }}</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('prices.destroy', $c->id) }}" class="inline" onsubmit="return confirm(@js(__('Qiymət kartı silinsin?')))">@csrf @method('DELETE')<button class="btn btn-sm btn-danger px-2" aria-label="{{ __('Sil') }}"><x-icon name="trash" size="12"/></button></form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="11" class="text-center text-muted">{{ __('Qiymət kartı yoxdur.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
