<x-layouts.app :title="$title">
    <script>
        function stockDocForm(config) {
            const products = new Map(config.products.map((p) => [p.id, p]));
            const norm = (s) => String(s ?? '').toLocaleLowerCase('az').trim();
            const line = (l) => {
                const p = products.get(Number(l.product_id));
                return { key: Math.random(), product_id: p ? p.id : null, factor: 1, count: l.qty ?? '', counted: l.counted_qty ?? '', cost: l.unit_cost ?? (p ? p.cost : ''), note: l.note ?? '' };
            };
            return {
                warehouse: config.warehouse, isCount: config.isCount, type: config.type,
                lines: config.lines.map(line), query: '', open: false, scan: '', scanMsg: '',
                product(id) { return products.get(id); },
                system(l) { const p = products.get(l.product_id); return p ? Number(p.stock[this.warehouse] ?? 0) : 0; },
                // sayımda sayılan miqdar → fərq; digərlərində miqdar × vahid əmsalı
                counted(l) { return l.counted === '' || l.counted === null ? null : Number(l.counted) * l.factor; },
                diff(l) { const c = this.counted(l); return c === null ? null : Math.round((c - this.system(l)) * 10000) / 10000; },
                baseQty(l) { if (this.isCount && this.counted(l) !== null) return Math.abs(this.diff(l)); return Math.round((Number(l.count) || 0) * l.factor * 10000) / 10000; },
                wrongSign(l) { const d = this.diff(l); if (d === null) return false; return this.type === 'count_surplus' ? d <= 0 : d >= 0; },
                matches() { const q = norm(this.query); return config.products.filter((p) => !q || norm(p.code + ' ' + p.name + ' ' + (p.barcode ?? '')).includes(q)).slice(0, 30); },
                add(p) { if (!this.lines.some((l) => l.product_id === p.id)) this.lines.push(line({ product_id: p.id })); this.query = ''; this.open = false; },
                scanned() {
                    const code = this.scan.trim(); if (!code) return;
                    const p = config.products.find((x) => x.barcode === code || x.code === code);
                    if (p) { this.add(p); this.scanMsg = '✓ ' + p.code + ' — ' + p.name; } else { this.scanMsg = '{{ __('Tapılmadı') }}: ' + code; }
                    this.scan = '';
                },
                total() { return this.lines.reduce((s, l) => s + this.baseQty(l), 0); },
                totalCost() { return this.lines.reduce((s, l) => s + this.baseQty(l) * (Number(l.cost) || 0) / (this.type === 'opening' ? 1 : 1), 0); },
                money: (v) => window.fmt.money(v),
            };
        }
    </script>

    <form method="POST" action="{{ route('stock-docs.store') }}" class="flex flex-col gap-4" x-data="stockDocForm(@js($config))">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('stock-docs.index', ['type' => $type]) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
            @foreach ($types as $key => $label)<a href="{{ route('stock-docs.create', ['type' => $key]) }}" @class(['btn btn-sm', 'btn-primary' => $key === $type])>{{ $label }}</a>@endforeach
        </div>
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $types[$type] }}</h2><div class="card-sub">{{ $help[$type] }}</div></div></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="sd-date">{{ __('Tarix') }}</label><input id="sd-date" type="date" name="date" required class="input" value="{{ old('date', now()->toDateString()) }}"></div>
                <div><label class="label" for="sd-wh">{{ __('Anbar') }}</label><select id="sd-wh" name="warehouse_id" class="select" x-model.number="warehouse">@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
                <div><label class="label" for="sd-wp">{{ __('İş yeri') }}</label><select id="sd-wp" name="workplace_id" class="select" required>@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected($workplaceId === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
                @if ($type === 'consumption')
                    <div><label class="label" for="sd-purpose">{{ __('Sərf məqsədi') }}</label><input id="sd-purpose" name="purpose" maxlength="255" class="input" list="sd-purposes" value="{{ old('purpose') }}">
                        <datalist id="sd-purposes">@foreach ([__('Daxili istifadə'), __('Nümunə (müştəriyə)'), __('Reklam / tədbir'), __('Servis / texniki istifadə'), __('Ofis ehtiyacı')] as $p)<option value="{{ $p }}">@endforeach</datalist></div>
                @endif
                <div class="sm:col-span-2 lg:col-span-4"><label class="label" for="sd-note">{{ __('Qeyd') }}</label><input id="sd-note" name="note" maxlength="2000" class="input" value="{{ old('note') }}"></div>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Məhsullar') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 border-b border-line p-4 md:grid-cols-2">
                <div><label class="label" for="sd-scan">{{ __('Barkod skaneri') }}</label>
                    <input id="sd-scan" class="input font-mono" autocomplete="off" placeholder="{{ __('Barkodu oxudun və ya yazıb Enter basın') }}" x-model="scan" @keydown.enter.prevent="scanned()">
                    <div class="mt-1 text-xs text-muted" x-text="scanMsg"></div></div>
                <div class="relative" @click.outside="open = false"><label class="label" for="sd-search">{{ __('Mal əlavə et') }}</label>
                    <input id="sd-search" class="input" autocomplete="off" placeholder="{{ __('Kod, ad və ya barkod…') }}" x-model="query" @focus="open = true" @input="open = true" @keydown.enter.prevent="matches()[0] && add(matches()[0])">
                    <ul x-show="open" x-cloak class="card absolute z-30 mt-1 max-h-72 w-full overflow-auto py-1 text-sm">
                        <template x-for="p in matches()" :key="p.id"><li><button type="button" class="grid w-full grid-cols-[90px_minmax(0,1fr)_auto] gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="add(p)">
                            <span class="font-mono text-xs text-muted" x-text="p.code"></span><span class="truncate" x-text="p.name"></span><span class="text-xs text-muted" x-text="p.stock[warehouse] ?? 0"></span></button></li></template>
                    </ul></div>
            </div>
            <div class="tbl-wrap"><table class="tbl">
                <thead><tr>
                    <th>#</th><th>{{ __('Mal') }}</th><th class="num">{{ __('Sistemdə') }}</th><th>{{ __('Vahid') }}</th>
                    @if (\App\Models\StockDocument::isCount($type))<th class="num">{{ __('Sayılan') }}</th><th class="num">{{ __('Fərq') }}</th>@else<th class="num">{{ __('Miqdar') }}</th>@endif
                    <th class="num">{{ __('Əsas vahiddə') }}</th>
                    <th class="num">{{ $type === 'opening' ? __('Vahid maya') : __('Təxmini maya') }}</th><th>{{ __('Qeyd') }}</th><th></th>
                </tr></thead>
                <tbody>
                    <template x-for="(l, i) in lines" :key="l.key">
                        <tr :class="wrongSign(l) ? 'row-neg' : ''">
                            <td class="text-muted" x-text="i + 1"></td>
                            <td class="wrap"><input type="hidden" :name="`lines[${i}][product_id]`" :value="l.product_id">
                                <span class="font-mono text-xs text-muted" x-text="product(l.product_id)?.code"></span> <span x-text="product(l.product_id)?.name"></span></td>
                            <td class="num text-muted" x-text="system(l) + ' ' + (product(l.product_id)?.base_unit ?? '')"></td>
                            <td><select class="select h-8 w-auto" x-model.number="l.factor" :aria-label="'{{ __('Vahid') }} ' + (i + 1)">
                                <option value="1" x-text="product(l.product_id)?.base_unit || '{{ __('əsas') }}'"></option>
                                <template x-for="u in (product(l.product_id)?.units ?? [])" :key="u.name"><option :value="u.factor" x-text="u.name + ' (' + u.factor + ')'"></option></template>
                            </select></td>
                            @if (\App\Models\StockDocument::isCount($type))
                                <td class="num"><input type="number" step="any" min="0" class="input h-8 w-24 text-right" x-model="l.counted" required :aria-label="'{{ __('Sayılan') }} ' + (i + 1)">
                                    <input type="hidden" :name="`lines[${i}][counted_qty]`" :value="counted(l) ?? ''"></td>
                                <td class="num font-semibold" :class="wrongSign(l) ? 'neg' : (diff(l) > 0 ? 'pos' : 'neg')" x-text="diff(l) === null ? '—' : (diff(l) > 0 ? '+' : '') + diff(l)"></td>
                            @else
                                <td class="num"><input type="number" step="any" min="0.0001" class="input h-8 w-24 text-right" x-model="l.count" required :aria-label="'{{ __('Miqdar') }} ' + (i + 1)">
                                    <input type="hidden" :name="`lines[${i}][qty]`" :value="baseQty(l)"></td>
                            @endif
                            <td class="num" x-text="baseQty(l)"></td>
                            <td class="num">
                                @if ($type === 'opening')
                                    <input type="number" step="0.0001" min="0" class="input h-8 w-28 text-right" :name="`lines[${i}][unit_cost]`" x-model="l.cost" required :aria-label="'{{ __('Vahid maya') }} ' + (i + 1)">
                                @else
                                    <span class="text-muted" x-text="money(product(l.product_id)?.cost ?? 0)"></span>
                                @endif
                            </td>
                            <td><input class="input h-8 w-40" maxlength="255" :name="`lines[${i}][note]`" x-model="l.note" :aria-label="'{{ __('Qeyd') }} ' + (i + 1)"></td>
                            <td><button type="button" class="btn btn-sm btn-danger px-2" @click="lines.splice(i, 1)" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" size="12"/></button></td>
                        </tr>
                    </template>
                    <tr x-show="!lines.length"><td colspan="10" class="text-center text-muted">{{ __('Mal əlavə edin: skaner və ya axtarış.') }}</td></tr>
                </tbody>
                <tfoot><tr><td colspan="{{ \App\Models\StockDocument::isCount($type) ? 6 : 5 }}">{{ __('Cəmi') }}</td><td class="num" x-text="total()"></td><td></td><td colspan="2"></td></tr></tfoot>
            </table></div>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line p-4">
                <span class="text-xs neg" x-show="lines.some((l) => wrongSign(l))" x-cloak>{{ __('Qırmızı sətirlərdə fərqin işarəsi sənəd növünə uyğun deyil (artıq ↔ əskik).') }}</span>
                <div class="ml-auto flex gap-2">
                    <a href="{{ route('stock-docs.index', ['type' => $type]) }}" class="btn">{{ __('Ləğv et') }}</a>
                    <button class="btn btn-primary" :disabled="!lines.length || lines.some((l) => wrongSign(l))">{{ __('Sənədi yarat') }}</button>
                </div>
            </div>
        </section>
    </form>
</x-layouts.app>
