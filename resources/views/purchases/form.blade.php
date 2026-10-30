<x-layouts.app :title="$title">
    <script>
        function purchaseForm(config) {
            const products = new Map(config.products.map((p) => [p.id, p]));
            const suppliers = new Map(config.suppliers.map((s) => [s.id, s]));
            const norm = (s) => String(s ?? '').toLocaleLowerCase('az').trim();
            const line = (l) => ({ key: Math.random(), product_id: Number(l.product_id) || null, qty: l.qty ?? 1, price: l.price ?? (products.get(Number(l.product_id))?.last ?? ''), note: l.note ?? '' });
            return {
                type: config.type, supplier_id: config.supplier, date: config.date, rate: config.rate ?? '', vatIncluded: config.vatIncluded,
                costs: config.costs, lines: config.lines.map(line), query: '', open: false, scan: '', scanMsg: '', rateMsg: '',
                init() { if (this.currency() !== 'AZN' && !this.rate) this.loadRate(); this.$watch('supplier_id', () => this.loadRate()); this.$watch('date', () => this.loadRate()); },
                supplier() { return suppliers.get(Number(this.supplier_id)) ?? null; },
                currency() { return this.supplier()?.currency ?? 'AZN'; },
                rateNum() { return this.currency() === 'AZN' ? 1 : (Number(this.rate) || 0); },
                async loadRate() {
                    if (this.currency() === 'AZN') { this.rate = 1; this.rateMsg = ''; return; }
                    this.rateMsg = @js(__('Mərkəzi Bank məzənnəsi yüklənir…'));
                    try {
                        const r = await fetch(config.rateUrl + '?code=' + this.currency() + '&date=' + this.date, { headers: { Accept: 'application/json' } });
                        const j = r.ok ? await r.json() : {};
                        if (j.rate) { this.rate = j.rate; this.rateMsg = @js(__('Mərkəzi Bank məzənnəsi')); } else { this.rateMsg = @js(__('Məzənnə tapılmadı — əl ilə yazın.')); }
                    } catch (e) { this.rateMsg = @js(__('Məzənnə tapılmadı — əl ilə yazın.')); }
                },
                product(id) { return products.get(id); },
                vatRate(l) { return this.product(l.product_id)?.vat ?? 0; },
                gross(l) { return (Number(l.qty) || 0) * (Number(l.price) || 0); },
                vat(l) { const r = this.vatRate(l); return this.vatIncluded ? this.gross(l) * r / (100 + r) : this.gross(l) * r / 100; },
                net(l) { return this.vatIncluded ? this.gross(l) - this.vat(l) : this.gross(l); },
                goods() { return this.lines.reduce((s, l) => s + this.net(l), 0); },
                vatTotal() { return this.lines.reduce((s, l) => s + this.vat(l), 0); },
                extra() { return this.type === 'receipt' ? (Number(this.costs.customs) || 0) + (Number(this.costs.transport) || 0) + (Number(this.costs.other) || 0) : 0; },
                // vahid maya (AZN): ƏDV-siz məbləğ × məzənnə + əlavə xərcin payı
                unitCost(l) { const g = this.goods(); const share = g > 0 ? this.extra() * this.net(l) / g : 0; const q = Number(l.qty) || 0; return q > 0 ? (this.net(l) * this.rateNum() + share) / q : 0; },
                matches() { const q = norm(this.query); return config.products.filter((p) => !q || norm(p.code + ' ' + p.name + ' ' + (p.barcode ?? '')).includes(q)).slice(0, 30); },
                add(p) { if (!this.lines.some((l) => l.product_id === p.id)) this.lines.push(line({ product_id: p.id })); this.query = ''; this.open = false; },
                scanned() {
                    const code = this.scan.trim(); if (!code) return;
                    const p = config.products.find((x) => x.barcode === code || x.code === code);
                    if (p) { this.add(p); this.scanMsg = '✓ ' + p.code + ' — ' + p.name; } else { this.scanMsg = @js(__('Tapılmadı')) + ': ' + code; }
                    this.scan = '';
                },
                num: (v, d = 2) => window.fmt.number(v, d), money: (v) => window.fmt.money(v),
            };
        }
    </script>

    <form method="POST" action="{{ route('purchase-docs.store') }}" class="flex flex-col gap-4" x-data="purchaseForm(@js($config))">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        @if ($original)<input type="hidden" name="original_document_id" value="{{ $original->id }}">@endif
        @if ($order)
            <input type="hidden" name="purchase_order_id" value="{{ $order->id }}">
            <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--primary)">
                {{ __('Alış sifarişi № :no əsasında: sətirlərdə hələ qəbul olunmamış qalıq var — faktiki gələn miqdarı yazın.', ['no' => $order->doc_no]) }}
                <a href="{{ route('purchase-orders.show', $order) }}" class="text-primary-text hover:underline">{{ $order->doc_no }}</a>
            </div>
        @endif
        @if ($einvoice)
            <input type="hidden" name="incoming_einvoice_id" value="{{ $einvoice->id }}">
            <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--primary)">
                {{ __('e-qaimə № :serial əsasında: qiymətlər ƏDV-sizdir; uyğunlaşdırılmamış mallar sətirlərə düşməyib — lazım olsa əl ilə əlavə edin.', ['serial' => $einvoice->serial_number]) }}
                <a href="{{ route('einvoices.incoming.show', $einvoice) }}" class="text-primary-text hover:underline">{{ __('Uyğunluğa qayıt') }}</a>
            </div>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('purchase-docs.index', ['type' => $type]) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
            @if ($original)<a href="{{ route('purchase-docs.show', $original) }}" class="badge badge-primary">{{ __('Əsas mədaxil') }}: {{ $original->doc_no }}</a>@endif
        </div>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $title }}</h2>
                <div class="card-sub">{{ $type === 'receipt' ? __('Mal anbara daxil olur. Maya = ƏDV-siz məbləğ × məzənnə + gömrük / daşınma / digər xərcin payı. ƏDV mayaya düşmür, təchizatçı borcuna daxildir.') : __('Mal təchizatçıya qaytarılır: stokdan çıxır, təchizatçı borcu azalır.') }}</div></div></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="pd-date">{{ __('Tarix') }}</label><input id="pd-date" type="date" name="date" required class="input" x-model="date" max="{{ now()->toDateString() }}"></div>
                <div class="sm:col-span-2">
                    <label class="label" for="pd-supplier">{{ __('Təchizatçı') }}</label>
                    <select id="pd-supplier" name="supplier_id" class="select" x-model.number="supplier_id" required>
                        <option value="">—</option>
                        <template x-for="s in @js($config['suppliers'])" :key="s.id"><option :value="s.id" x-text="s.name + ' · ' + s.code + ' (' + s.currency + ')'" :selected="s.id === supplier_id"></option></template>
                    </select>
                    @if (! count($config['suppliers']))<a href="{{ route('suppliers.create') }}" class="mt-1 inline-block text-xs text-primary-text hover:underline">{{ __('Əvvəlcə təchizatçı əlavə edin') }}</a>@endif
                </div>
                <div>
                    <label class="label" for="pd-rate">{{ __('Məzənnə') }} <span class="text-muted" x-text="'1 ' + currency() + ' = ? AZN'"></span></label>
                    <input id="pd-rate" name="rate" type="number" step="0.000001" min="0" class="input" x-model="rate" :readonly="currency() === 'AZN'" :required="currency() !== 'AZN'">
                    <div class="mt-1 text-xs text-muted" x-text="rateMsg"></div>
                </div>
                <div><label class="label" for="pd-wh">{{ __('Anbar') }}</label><select id="pd-wh" name="warehouse_id" class="select">@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($values['warehouse_id'] === $w->id)>{{ $w->name }}</option>@endforeach</select></div>
                <div><label class="label" for="pd-wp">{{ __('İş yeri') }}</label><select id="pd-wp" name="workplace_id" class="select">@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected($values['workplace_id'] === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
                <div><label class="label" for="pd-inv">{{ __('Təchizatçı qaiməsi №') }}</label><input id="pd-inv" name="supplier_invoice_no" maxlength="64" class="input" value="{{ old('supplier_invoice_no', $einvoice?->serial_number) }}"></div>
                <div><label class="label" for="pd-invd">{{ __('Qaimə tarixi') }}</label><input id="pd-invd" name="supplier_invoice_date" type="date" class="input" value="{{ old('supplier_invoice_date', $einvoice?->issued_at?->toDateString()) }}"></div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="hidden" name="vat_included" value="0"><input type="checkbox" name="vat_included" value="1" class="size-4" x-model="vatIncluded"> {{ __('Qiymətlərə ƏDV daxildir') }}</label>
                @if ($type === 'receipt')
                    <div><label class="label" for="pd-customs">{{ __('Gömrük xərci') }} (₼)</label><input id="pd-customs" name="customs_cost" type="number" step="0.01" min="0" class="input" x-model="costs.customs"></div>
                    <div><label class="label" for="pd-transport">{{ __('Daşınma xərci') }} (₼)</label><input id="pd-transport" name="transport_cost" type="number" step="0.01" min="0" class="input" x-model="costs.transport"></div>
                    <div><label class="label" for="pd-other">{{ __('Digər xərc') }} (₼)</label><input id="pd-other" name="other_cost" type="number" step="0.01" min="0" class="input" x-model="costs.other"></div>
                @endif
                <div class="sm:col-span-2 lg:col-span-4"><label class="label" for="pd-note">{{ __('Qeyd') }}</label><input id="pd-note" name="note" maxlength="2000" class="input" value="{{ old('note') }}"></div>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Məhsullar') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 border-b border-line p-4 md:grid-cols-2">
                <div><label class="label" for="pd-scan">{{ __('Barkod skaneri') }}</label>
                    <input id="pd-scan" class="input font-mono" autocomplete="off" placeholder="{{ __('Barkodu oxudun və ya yazıb Enter basın') }}" x-model="scan" @keydown.enter.prevent="scanned()">
                    <div class="mt-1 text-xs text-muted" x-text="scanMsg"></div></div>
                <div class="relative" @click.outside="open = false"><label class="label" for="pd-q">{{ __('Mal əlavə et') }}</label>
                    <input id="pd-q" class="input" autocomplete="off" placeholder="{{ __('Kod, ad və ya barkod…') }}" x-model="query" @focus="open = true" @input="open = true" @keydown.enter.prevent="matches()[0] && add(matches()[0])">
                    <ul x-show="open" x-cloak class="card absolute z-30 mt-1 max-h-72 w-full overflow-auto py-1 text-sm">
                        <template x-for="p in matches()" :key="p.id"><li><button type="button" class="flex w-full justify-between gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="add(p)"><span class="truncate" x-text="p.name"></span><span class="shrink-0 text-xs text-muted" x-text="p.code"></span></button></li></template>
                    </ul></div>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead><tr class="border-b border-line text-left text-xs uppercase text-muted">
                        <th class="px-3 py-2">#</th><th class="px-3 py-2">{{ __('Mal') }}</th><th class="px-3 py-2 text-right">{{ __('Miqdar') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Qiymət') }} <span x-text="'(' + currency() + ')'"></span></th><th class="px-3 py-2 text-right">{{ __('ƏDV-siz') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('ƏDV') }}</th><th class="px-3 py-2 text-right" x-show="type === 'receipt'">{{ __('Vahid maya (₼)') }}</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="(l, i) in lines" :key="l.key">
                            <tr class="border-b border-line">
                                <td class="px-3 py-2 text-muted" x-text="i + 1"></td>
                                <td class="px-3 py-2"><input type="hidden" :name="`lines[${i}][product_id]`" :value="l.product_id">
                                    <div class="font-medium" x-text="product(l.product_id)?.name ?? '—'"></div><div class="font-mono text-xs text-muted" x-text="product(l.product_id)?.code ?? ''"></div></td>
                                <td class="px-3 py-2"><input type="number" step="any" min="0.0001" class="input w-24 text-right" :name="`lines[${i}][qty]`" x-model="l.qty" required :aria-label="@js(__('Miqdar'))"></td>
                                <td class="px-3 py-2"><input type="number" step="any" min="0" class="input w-28 text-right" :name="`lines[${i}][price]`" x-model="l.price" required :aria-label="@js(__('Qiymət'))"></td>
                                <td class="px-3 py-2 text-right tabular-nums" x-text="num(net(l))"></td>
                                <td class="px-3 py-2 text-right tabular-nums"><span x-text="num(vat(l))"></span><div class="text-xs text-muted" x-text="vatRate(l) + ' %'"></div></td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold" x-show="type === 'receipt'" x-text="num(unitCost(l), 4)"></td>
                                <td class="px-3 py-2 text-right"><button type="button" class="btn btn-sm btn-danger h-8 px-2" @click="lines.splice(i, 1)" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" size="14"/></button></td>
                            </tr>
                        </template>
                        <tr x-show="!lines.length"><td colspan="8" class="px-3 py-6 text-center text-muted">{{ __('Mal əlavə edin: skaner və ya axtarış.') }}</td></tr>
                    </tbody>
                    <tfoot class="text-sm">
                        <tr><td colspan="4" class="px-3 pt-3 text-right text-muted">{{ __('ƏDV-siz məbləğ') }}</td><td class="px-3 pt-3 text-right tabular-nums" x-text="num(goods()) + ' ' + currency()"></td><td colspan="3"></td></tr>
                        <tr><td colspan="4" class="px-3 text-right text-muted">{{ __('ƏDV') }}</td><td class="px-3 text-right tabular-nums" x-text="num(vatTotal()) + ' ' + currency()"></td><td colspan="3"></td></tr>
                        <tr><td colspan="4" class="px-3 text-right font-semibold">{{ __('Təchizatçıya borc') }}</td><td class="px-3 text-right font-semibold tabular-nums" x-text="num(goods() + vatTotal()) + ' ' + currency()"></td><td colspan="3"></td></tr>
                        <tr x-show="type === 'receipt'"><td colspan="4" class="px-3 pb-3 text-right text-muted">{{ __('Stok dəyəri (AZN, əlavə xərclə)') }}</td><td class="px-3 pb-3 text-right tabular-nums" x-text="money(goods() * rateNum() + extra())"></td><td colspan="3"></td></tr>
                    </tfoot>
                </table>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-line p-4">
                <a href="{{ route('purchase-docs.index', ['type' => $type]) }}" class="btn">{{ __('Ləğv et') }}</a>
                <button class="btn btn-primary" :disabled="!supplier_id || !lines.length">{{ __('Sənədi yarat') }}</button>
            </div>
        </section>
    </form>
</x-layouts.app>
