<x-layouts.app :title="$title">
    <script>
        function transferForm(config) {
            const products = new Map(config.products.map((p) => [p.id, p]));
            const warehouses = new Map(config.warehouses.map((w) => [w.id, w]));
            const norm = (s) => String(s ?? '').toLocaleLowerCase('az').trim();
            const v = config.values;
            const line = (productId, qty) => ({ key: Math.random(), product_id: productId, factor: 1, count: qty });
            return {
                from: v.from, to: v.to, driver: v.driver, plate: v.vehicle_plate,
                lines: v.lines.map((l) => line(Number(l.product_id), Number(l.qty))),
                query: '', open: false, scan: '', scanMsg: '', loading: false, suggestMsg: '',
                product(id) { return products.get(id); },
                stock(id, wh) { const p = products.get(id); return p ? Number(p.stock[wh] ?? 0) : 0; },
                qty(l) { return Math.round((Number(l.count) || 0) * Number(l.factor) * 10000) / 10000; },
                matches() {
                    const q = norm(this.query);
                    return config.products.filter((p) => !q || norm(p.code + ' ' + p.name + ' ' + (p.barcode ?? '')).includes(q)).slice(0, 30);
                },
                add(productId, qty = 1, factor = 1) {
                    const existing = this.lines.find((l) => l.product_id === productId && Number(l.factor) === Number(factor));
                    if (existing) existing.count = Number(existing.count) + qty; else this.lines.push({ ...line(productId, qty), factor });
                },
                pick(p) { this.add(p.id, 1, p.sale_factor || 1); this.query = ''; this.open = false; },
                // barkod skaneri: Enter ilə — qutu barkodu qutunun əmsalı ilə əlavə olunur
                scanned() {
                    const code = this.scan.trim();
                    if (!code) return;
                    const hit = config.barcodes.find((b) => b.barcode === code);
                    const p = hit ? products.get(hit.product_id) : config.products.find((x) => x.barcode === code || x.code === code);
                    if (p) { this.add(p.id, 1, hit ? hit.factor : 1); this.scanMsg = '✓ ' + p.code + ' — ' + p.name; } else { this.scanMsg = '{{ __('Tapılmadı') }}: ' + code; }
                    this.scan = '';
                },
                fromChanged() { },
                toChanged() {
                    const w = warehouses.get(Number(this.to));
                    if (w && w.plate && !this.plate) this.plate = w.plate;
                    if (w && w.responsible && !this.driver) this.driver = w.responsible;
                },
                async suggest() {
                    this.loading = true; this.suggestMsg = '';
                    try {
                        const r = await fetch(config.suggestUrl + '?from=' + this.from + '&to=' + this.to + '&days=14', { headers: { Accept: 'application/json' } });
                        const rows = r.ok ? await r.json() : [];
                        rows.forEach((s) => { if (!this.lines.some((l) => l.product_id === s.product_id)) this.lines.push(line(s.product_id, s.qty)); });
                        this.suggestMsg = rows.length ? '{{ __(':n mal təklif olundu (son 14 günün satışı − mövcud qalıq).') }}'.replace(':n', rows.length) : '{{ __('Təklif yoxdur: təyinat anbarında son 14 gündə satış olmayıb və ya qalıq kifayətdir.') }}';
                    } finally { this.loading = false; }
                },
                short(l) { return this.qty(l) > this.stock(l.product_id, this.from) + 1e-6; },
                total() { return this.lines.reduce((s, l) => s + this.qty(l), 0); },
            };
        }
    </script>

    <form method="POST" action="{{ $transfer ? route('transfers.update', $transfer) : route('transfers.store') }}" class="flex flex-col gap-4" x-data="transferForm(@js($config))">
        @csrf
        @if ($transfer) @method('PUT') @endif
        <div><a href="{{ $transfer ? route('transfers.show', $transfer) : route('transfers.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a></div>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Transfer detalları') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="t-date">{{ __('Tarix') }}</label><input id="t-date" type="date" name="date" required class="input" value="{{ $config['values']['date'] }}"></div>
                <div><label class="label" for="t-from">{{ __('Haradan') }}</label>
                    <select id="t-from" name="from_warehouse_id" class="select" x-model.number="from" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
                <div><label class="label" for="t-to">{{ __('Haraya') }}</label>
                    <select id="t-to" name="to_warehouse_id" class="select" x-model.number="to" @change="toChanged()" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
                    <div class="mt-1 text-xs neg" x-show="from === to" x-cloak>{{ __('Mənbə və təyinat eyni ola bilməz.') }}</div></div>
                <div><label class="label" for="t-reason">{{ __('Səbəb') }}</label>
                    <select id="t-reason" name="reason" class="select">@foreach ($reasons as $k => $l)<option value="{{ $k }}" @selected($config['values']['reason'] === $k)>{{ $l }}</option>@endforeach</select></div>
                <div><label class="label" for="t-driver">{{ __('Sürücü / məsul') }}</label><input id="t-driver" name="driver" maxlength="255" class="input" x-model="driver"></div>
                <div><label class="label" for="t-plate">{{ __('Maşın nömrəsi') }}</label><input id="t-plate" name="vehicle_plate" maxlength="20" class="input" x-model="plate" placeholder="10-AB-123"></div>
<div><label class="label" for="t-wp">{{ __('İş yeri') }}</label>
                    <select id="t-wp" name="workplace_id" class="select" required>@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected((int) $workplaceId === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
                <div><label class="label" for="t-note">{{ __('Qeyd') }}</label><input id="t-note" name="note" maxlength="2000" class="input" value="{{ $config['values']['note'] }}"></div>
            </div>
        </section>

        <section class="card">
            <div class="card-head flex-wrap">
                <div><h2 class="card-title">{{ __('Məhsullar') }}</h2><div class="card-sub">{{ __('Sərbəst = mənbə anbarın real stoku − sifarişlərdə rezerv.') }}</div></div>
                <button type="button" class="btn btn-sm" @click="suggest()" :disabled="loading || from === to"><x-icon name="refresh" size="14"/> {{ __('Tövsiyə ilə doldur') }}</button>
            </div>
            <div class="grid grid-cols-1 gap-3 border-b border-line p-4 md:grid-cols-2">
                <div>
                    <label class="label" for="t-scan">{{ __('Barkod skaneri') }}</label>
                    <input id="t-scan" class="input font-mono" autocomplete="off" placeholder="{{ __('Barkodu oxudun və ya yazıb Enter basın') }}" x-model="scan" @keydown.enter.prevent="scanned()">
                    <div class="mt-1 text-xs text-muted" x-text="scanMsg"></div>
                </div>
                <div class="relative" @click.outside="open = false">
                    <label class="label" for="t-search">{{ __('Mal əlavə et') }}</label>
                    <input id="t-search" class="input" autocomplete="off" placeholder="{{ __('Kod, ad və ya barkod…') }}" x-model="query" @focus="open = true" @input="open = true" @keydown.enter.prevent="matches()[0] && pick(matches()[0])">
                    <ul x-show="open" x-cloak class="card absolute z-30 mt-1 max-h-72 w-full overflow-auto py-1 text-sm">
                        <template x-for="p in matches()" :key="p.id">
                            <li><button type="button" class="grid w-full grid-cols-[90px_minmax(0,1fr)_auto] gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="pick(p)">
                                <span class="font-mono text-xs text-muted" x-text="p.code"></span><span class="truncate" x-text="p.name"></span>
                                <span class="text-xs" :class="stock(p.id, from) > 0 ? 'text-muted' : 'neg'" x-text="stock(p.id, from)"></span>
                            </button></li>
                        </template>
                    </ul>
                </div>
                <div class="text-xs text-muted md:col-span-2" x-show="suggestMsg" x-text="suggestMsg"></div>
            </div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>#</th><th>{{ __('Mal') }}</th><th class="num">{{ __('Sərbəst (mənbə)') }}</th><th class="num">{{ __('Təyinatda') }}</th><th>{{ __('Vahid') }}</th><th class="num">{{ __('Say') }}</th><th class="num">{{ __('Əsas vahiddə') }}</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="(l, i) in lines" :key="l.key">
                            <tr :class="short(l) ? 'row-neg' : ''">
                                <td class="text-muted" x-text="i + 1"></td>
                                <td class="wrap"><input type="hidden" :name="`lines[${i}][product_id]`" :value="l.product_id"><input type="hidden" :name="`lines[${i}][qty]`" :value="qty(l)">
                                    <span class="font-mono text-xs text-muted" x-text="product(l.product_id)?.code"></span> <span x-text="product(l.product_id)?.name"></span></td>
                                <td class="num" :class="short(l) ? 'neg font-semibold' : ''" x-text="stock(l.product_id, from)"></td>
                                <td class="num text-muted" x-text="stock(l.product_id, to)"></td>
                                <td><select class="select h-8 w-auto" x-model.number="l.factor" :aria-label="'{{ __('Vahid') }} ' + (i + 1)">
                                    <option value="1">{{ __('əsas') }}</option>
                                    <template x-for="u in (product(l.product_id)?.units ?? [])" :key="u.name"><option :value="u.factor" x-text="u.name + ' (' + u.factor + ')'"></option></template>
                                </select></td>
                                <td class="num"><input type="number" step="any" min="0.0001" class="input h-8 w-24 text-right" x-model="l.count" required :aria-label="'{{ __('Say') }} ' + (i + 1)"></td>
                                <td class="num font-semibold" x-text="qty(l)"></td>
                                <td><button type="button" class="btn btn-sm btn-danger px-2" @click="lines.splice(i, 1)" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" size="12"/></button></td>
                            </tr>
                        </template>
                        <tr x-show="!lines.length"><td colspan="8" class="text-center text-muted">{{ __('Mal əlavə edin: skaner, axtarış və ya «Tövsiyə ilə doldur».') }}</td></tr>
                    </tbody>
                    <tfoot><tr><td colspan="6">{{ __('Cəmi') }}</td><td class="num" x-text="total()"></td><td></td></tr></tfoot>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line p-4">
                <span class="text-xs neg" x-show="lines.some((l) => short(l))" x-cloak>{{ __('Bəzi mallar üçün mənbədə sərbəst qalıq çatmır — göndərmək mümkün olmayacaq.') }}</span>
                <div class="ml-auto flex flex-wrap gap-2">
                    <a href="{{ route('transfers.index') }}" class="btn">{{ __('Ləğv et') }}</a>
                    <button class="btn" :disabled="!lines.length || from === to">{{ __('Qaralama saxla') }}</button>
                    <button name="then" value="send" class="btn" :disabled="!lines.length || from === to" title="{{ __('Mal tranzitə keçir, təyinat anbarı qəbul etməlidir') }}"><x-icon name="send" size="14"/> {{ __('Saxla və göndər') }}</button>
                    <button name="then" value="complete" class="btn btn-primary" :disabled="!lines.length || from === to" title="{{ __('Eyni anda göndərilir və qəbul edilir') }}"><x-icon name="truck" size="14"/> {{ __('Göndər və qəbul et') }}</button>
                </div>
            </div>
        </section>
    </form>
</x-layouts.app>
