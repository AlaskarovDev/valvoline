<x-layouts.app :title="$title">
    <script>
        function salesReturnForm(config) {
            const norm = (s) => String(s ?? '').toLocaleLowerCase('az').trim();
            const products = new Map(config.products.map((p) => [p.id, p]));
            const customers = new Map(config.customers.map((c) => [c.id, c]));
            const c = customers.get(config.customer);
            return {
                fromInvoice: config.invoiceLines.length > 0,
                lines: config.invoiceLines.length ? config.invoiceLines.map((l) => ({ ...l, key: l.sales_line_id, qty: '' })) : [],
                customer_id: c ? c.id : null, customerQuery: c ? c.name + ' · ' + c.code : '', cOpen: false, query: '', open: false,
                customerMatches() { const q = norm(this.customerQuery); return config.customers.filter((x) => !q || norm(x.name + ' ' + x.code).includes(q)).slice(0, 30); },
                pickCustomer(x) { this.customer_id = x.id; this.customerQuery = x.name + ' · ' + x.code; this.cOpen = false; },
                matches() { const q = norm(this.query); return config.products.filter((p) => !q || norm(p.code + ' ' + p.name + ' ' + (p.barcode ?? '')).includes(q)).slice(0, 30); },
                add(p) { if (!this.lines.some((l) => l.product_id === p.id)) this.lines.push({ key: Math.random(), product_id: p.id, code: p.code, name: p.name, qty: 1, price: p.price, available: null }); this.query = ''; this.open = false; },
                total() { return this.lines.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.price) || 0), 0); },
                money: (v) => window.fmt.money(v), qtyFmt: (v) => window.fmt.number(v, 2),
            };
        }
    </script>
    <form method="POST" action="{{ route('sales-returns.store') }}" class="flex flex-col gap-4" x-data="salesReturnForm(@js($config))">
        @csrf
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $invoice ? route('invoices.show', $invoice) : route('sales-returns.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
            @if ($invoice)<a href="{{ route('invoices.show', $invoice) }}" class="badge badge-primary">{{ __('Faktura') }}: {{ $invoice->doc_no }}</a>@endif
        </div>
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ __('Qaytarma qaiməsi') }}</h2>
                <div class="card-sub">{{ $invoice ? __('Fakturadan qaytarılır: qiymət fakturadakı (endirimli, ƏDV daxil) qiymətdir, miqdar satılandan çox ola bilməz.') : __('Fakturasız qaytarma: qiyməti əl ilə yazın.') }}</div></div></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="sr-date">{{ __('Tarix') }}</label><input id="sr-date" type="date" name="date" required class="input" value="{{ old('date', now()->toDateString()) }}" max="{{ now()->toDateString() }}"></div>
                @if ($invoice)
                    <input type="hidden" name="sales_invoice_id" value="{{ $invoice->id }}"><input type="hidden" name="customer_id" value="{{ $invoice->customer_id }}">
                    <div class="sm:col-span-2"><span class="label">{{ __('Müştəri') }}</span><div class="pt-2 font-medium">{{ $invoice->customer?->name ?? $invoice->customer_name }}</div></div>
                @else
                    <div class="relative sm:col-span-2" @click.outside="cOpen = false">
                        <label class="label" for="sr-cust">{{ __('Müştəri') }}</label>
                        <input type="hidden" name="customer_id" :value="customer_id">
                        <input id="sr-cust" class="input" autocomplete="off" required placeholder="{{ __('Ad və ya kod…') }}" x-model="customerQuery" @focus="cOpen = true" @input="cOpen = true; customer_id = null">
                        <ul x-show="cOpen" x-cloak class="card absolute z-30 mt-1 max-h-64 w-full overflow-auto py-1 text-sm">
                            <template x-for="x in customerMatches()" :key="x.id"><li><button type="button" class="flex w-full justify-between gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="pickCustomer(x)"><span x-text="x.name"></span><span class="text-xs text-muted" x-text="x.code"></span></button></li></template>
                        </ul>
                    </div>
                @endif
                <div><label class="label" for="sr-reason">{{ __('Səbəb') }}</label><select id="sr-reason" name="reason" class="select">@foreach ($reasons as $k => $l)<option value="{{ $k }}" @selected(old('reason') === $k)>{{ $l }}</option>@endforeach</select></div>
                <div><label class="label" for="sr-wh">{{ __('Anbara qayıdır') }}</label><select id="sr-wh" name="warehouse_id" class="select">@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($values['warehouse_id'] === $w->id)>{{ $w->name }}@if ($w->type === 'quarantine') ({{ __('karantin') }})@endif</option>@endforeach</select></div>
                <div><label class="label" for="sr-wp">{{ __('İş yeri') }}</label><select id="sr-wp" name="workplace_id" class="select">@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected($values['workplace_id'] === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
                <div class="sm:col-span-2"><label class="label" for="sr-note">{{ __('Qeyd') }}</label><input id="sr-note" name="note" maxlength="2000" class="input" value="{{ old('note') }}"></div>
            </div>
        </section>
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Qaytarılan mallar') }}</h2></div>
            <div class="relative border-b border-line p-4" x-show="!fromInvoice" @click.outside="open = false">
                <label class="label" for="sr-q">{{ __('Mal əlavə et') }}</label>
                <input id="sr-q" class="input" autocomplete="off" placeholder="{{ __('Kod, ad və ya barkod…') }}" x-model="query" @focus="open = true" @input="open = true" @keydown.enter.prevent="matches()[0] && add(matches()[0])">
                <ul x-show="open" x-cloak class="card absolute z-30 mt-1 max-h-72 w-[calc(100%-2rem)] overflow-auto py-1 text-sm">
                    <template x-for="p in matches()" :key="p.id"><li><button type="button" class="flex w-full justify-between gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="add(p)"><span class="truncate" x-text="p.name"></span><span class="text-xs text-muted" x-text="p.code"></span></button></li></template>
                </ul>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead><tr class="border-b border-line text-left text-xs uppercase text-muted"><th class="px-3 py-2">{{ __('Mal') }}</th><th class="px-3 py-2 text-right" x-show="fromInvoice">{{ __('Satılıb / qaytarılıb') }}</th><th class="px-3 py-2 text-right">{{ __('Qaytarılır') }}</th><th class="px-3 py-2 text-right">{{ __('Qiymət (ƏDV daxil)') }}</th><th class="px-3 py-2 text-right">{{ __('Məbləğ') }}</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="(l, i) in lines" :key="l.key">
                            <tr class="border-b border-line">
                                <td class="px-3 py-2"><input type="hidden" :name="`lines[${i}][product_id]`" :value="l.product_id"><input type="hidden" :name="`lines[${i}][sales_line_id]`" :value="l.sales_line_id ?? ''">
                                    <div class="font-medium" x-text="l.name"></div><div class="font-mono text-xs text-muted" x-text="l.code"></div></td>
                                <td class="px-3 py-2 text-right tabular-nums text-muted" x-show="fromInvoice" x-text="qtyFmt(l.sold) + ' / ' + qtyFmt(l.returned)"></td>
                                <td class="px-3 py-2 text-right"><input type="number" step="any" min="0" :max="l.available ?? null" class="input w-24 text-right" :name="`lines[${i}][qty]`" x-model="l.qty" :aria-label="@js(__('Miqdar'))" :placeholder="l.available !== null ? '≤ ' + qtyFmt(l.available) : ''"></td>
                                <td class="px-3 py-2 text-right"><input type="number" step="any" min="0" class="input w-28 text-right" :name="`lines[${i}][price]`" x-model="l.price" :readonly="fromInvoice" :aria-label="@js(__('Qiymət'))"></td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold" x-text="money((Number(l.qty) || 0) * (Number(l.price) || 0))"></td>
                                <td class="px-3 py-2 text-right"><button type="button" x-show="!fromInvoice" class="btn btn-sm btn-danger h-8 px-2" @click="lines.splice(i, 1)" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" size="14"/></button></td>
                            </tr>
                        </template>
                        <tr x-show="!lines.length"><td colspan="6" class="px-3 py-6 text-center text-muted">{{ __('Mal əlavə edin.') }}</td></tr>
                    </tbody>
                    <tfoot><tr><td colspan="6" class="px-3 py-3 text-right font-semibold">{{ __('Müştəri borcundan çıxılır') }}: <span class="tabular-nums" x-text="money(total())"></span></td></tr></tfoot>
                </table>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-line p-4">
                <a href="{{ $invoice ? route('invoices.show', $invoice) : route('sales-returns.index') }}" class="btn">{{ __('Ləğv et') }}</a>
                <button class="btn btn-primary" :disabled="total() <= 0 || (!fromInvoice && !customer_id)">{{ __('Qaytarmanı yaz') }}</button>
            </div>
        </section>
    </form>
</x-layouts.app>
