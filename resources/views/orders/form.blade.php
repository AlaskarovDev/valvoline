@php
    $config = [
        'customers' => $customers,
        'products' => $products,
        'values' => $values,
        'orderId' => $order?->id,
        'previewUrl' => route('orders.campaigns'),
        'opTypes' => ['sale' => __('Satış'), 'gift' => __('Hədiyyə / pulsuz'), 'internal' => __('Daxili istifadə')],
        'text' => ['error' => __('Kampaniyalar hesablanmadı. Yenidən cəhd edin.'), 'none' => __('Bu sifarişə uyğun kampaniya yoxdur.')],
    ];
@endphp
<x-layouts.app :title="$title">
    <script>
        function orderForm(config) {
            const products = new Map(config.products.map((p) => [p.id, p]));
            const customers = new Map(config.customers.map((c) => [c.id, c]));
            const norm = (s) => String(s ?? '').toLocaleLowerCase('az').replace(/\s+/g, ' ').trim();
            const blank = () => ({ key: Math.random(), product_id: null, op_type: 'sale', qty: 1, price: 0, discount: 0, query: '', open: false });
            const line = (l) => {
                const p = products.get(Number(l.product_id));
                return { ...blank(), ...l, product_id: p ? p.id : null, query: p ? p.code + ' — ' + p.name : '' };
            };
            const c = customers.get(Number(config.values.customer_id));

            return {
                warehouse_id: Number(config.values.warehouse_id),
                date: config.values.date,
                customer_id: c ? c.id : null,
                customerQuery: c ? c.name + ' · ' + c.code : '',
                customerOpen: false,
                manager_id: config.values.manager_id ? Number(config.values.manager_id) : '',
                lines: (config.values.lines.length ? config.values.lines : [{}]).map(line),
                opTypes: config.opTypes,
                // kampaniyalar
                applyCampaigns: !!config.values.apply_campaigns,
                excluded: config.values.excluded_campaigns.map(Number),
                campaignResult: null,
                campaignLoading: false,
                campaignError: '',
                timer: null,

                init() {
                    if (this.applyCampaigns) this.refreshCampaigns();
                    this.$watch(() => JSON.stringify([this.customer_id, this.date, this.lines.map((l) => [l.product_id, l.op_type, l.qty, l.price, l.discount])]), () => {
                        if (!this.applyCampaigns) return;
                        clearTimeout(this.timer);
                        this.timer = setTimeout(() => this.refreshCampaigns(), 400);
                    });
                },

                customerMatches() {
                    const q = norm(this.customerQuery);
                    return config.customers.filter((c) => !q || norm(c.name + ' ' + c.code + ' ' + (c.voen ?? '')).includes(q)).slice(0, 30);
                },
                pickCustomer(c) {
                    this.customer_id = c.id;
                    this.customerQuery = c.name + ' · ' + c.code;
                    this.customerOpen = false;
                    // satış nümayəndəsi müştəridən avtomatik gəlir
                    if (c.manager_id && !this.$refs.manager.disabled) this.manager_id = c.manager_id;
                },
                customer() { return customers.get(this.customer_id) ?? null; },

                productMatches(l) {
                    const q = norm(l.query);
                    return config.products.filter((p) => !q || norm(p.code + ' ' + p.name).includes(q)).slice(0, 40);
                },
                pickProduct(l, p) {
                    l.product_id = p.id;
                    l.query = p.code + ' — ' + p.name;
                    l.price = l.op_type === 'sale' ? p.price : 0;
                    l.open = false;
                },
                stockOf(productId) {
                    const p = products.get(productId);
                    return p ? Number(p.stock[this.warehouse_id] ?? 0) : null;
                },
                addLine() {
                    this.lines.push(blank());
                    this.$nextTick(() => { const inputs = this.$root.querySelectorAll('[data-product-input]'); inputs[inputs.length - 1]?.focus(); });
                },
                removeLine(i) { this.lines.splice(i, 1); if (!this.lines.length) this.lines.push(blank()); },
                gross(l) { return (Number(l.qty) || 0) * (Number(l.price) || 0); },
                net(l) { return this.gross(l) * (1 - (Number(l.discount) || 0) / 100); },
                lineResult(i) { return this.applyCampaigns && this.campaignResult ? (this.campaignResult.lines[i] ?? null) : null; },
                campaignDiscount(i) { return this.lineResult(i)?.campaign_discount ?? 0; },
                final(l, i) { return this.net(l) - this.campaignDiscount(i); },
                giftsOf(i) { return this.applyCampaigns && this.campaignResult ? this.campaignResult.gifts.filter((g) => g.line === i) : []; },
                sum(fn) { return this.lines.reduce((s, l, i) => s + (l.op_type === 'sale' ? fn(l, i) : 0), 0); },
                money: (v) => window.fmt.money(v),
                qtyFmt: (v) => window.fmt.number(v),

                giveCampaigns() {
                    this.applyCampaigns = true;
                    this.refreshCampaigns();
                },
                removeCampaigns() {
                    this.applyCampaigns = false;
                    this.campaignResult = null;
                    this.excluded = [];
                },
                toggleCampaign(id) {
                    this.excluded = this.excluded.includes(id) ? this.excluded.filter((x) => x !== id) : [...this.excluded, id];
                    this.refreshCampaigns();
                },
                async refreshCampaigns() {
                    if (!this.customer_id || !this.lines.some((l) => l.product_id)) { this.campaignResult = null; return; }
                    this.campaignLoading = true;
                    this.campaignError = '';
                    try {
                        const response = await fetch(config.previewUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({
                                customer_id: this.customer_id, date: this.date, order_id: config.orderId, excluded: this.excluded,
                                lines: this.lines.map((l) => ({ product_id: l.product_id, op_type: l.op_type, qty: Number(l.qty) || 0, price: Number(l.price) || 0, discount: Number(l.discount) || 0 })),
                            }),
                        });
                        if (!response.ok) throw new Error(response.status);
                        this.campaignResult = await response.json();
                    } catch (e) {
                        this.campaignError = config.text.error;
                    } finally {
                        this.campaignLoading = false;
                    }
                },
            };
        }
    </script>

    <form method="POST" action="{{ $order ? route('orders.update', $order) : route('orders.store') }}" class="flex flex-col gap-4" x-data="orderForm(@js($config))">
        @csrf
        @if ($order) @method('PUT') @endif
        <input type="hidden" name="apply_campaigns" :value="applyCampaigns ? 1 : 0">
        <template x-for="id in excluded" :key="id"><input type="hidden" name="excluded_campaigns[]" :value="id"></template>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $order ? route('orders.show', $order) : route('orders.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        </div>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Sifariş detalları') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="o-date">{{ __('Tarix') }}</label><input id="o-date" type="date" name="date" required class="input" x-model="date"></div>
                <div><label class="label" for="o-no">{{ __('Sifariş №') }}</label><input id="o-no" name="order_no" required maxlength="64" class="input" value="{{ $values['order_no'] }}"></div>
                <div><label class="label" for="o-invoice">{{ __('Qaimə №') }}</label><input id="o-invoice" name="invoice_no" maxlength="64" class="input" value="{{ $values['invoice_no'] }}"></div>
                <div>
                    <label class="label" for="o-warehouse">{{ __('Anbar') }}</label>
                    <select id="o-warehouse" name="warehouse_id" class="select" x-model.number="warehouse_id" required>
                        @foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                    </select>
                </div>

                <div class="relative sm:col-span-2" @click.outside="customerOpen = false">
                    <label class="label" for="o-customer">{{ __('Müştəri') }}</label>
                    <input type="hidden" name="customer_id" :value="customer_id">
                    <input id="o-customer" class="input" autocomplete="off" required placeholder="{{ __('Ad, kod və ya VÖEN ilə axtar…') }}"
                           x-model="customerQuery" @focus="customerOpen = true" @input="customerOpen = true; customer_id = null" @keydown.escape="customerOpen = false"
                           @keydown.enter.prevent="customerMatches()[0] && pickCustomer(customerMatches()[0])">
                    <ul x-show="customerOpen" x-cloak class="card absolute z-30 mt-1 max-h-72 w-full overflow-auto py-1 text-sm">
                        <template x-for="c in customerMatches()" :key="c.id">
                            <li><button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="pickCustomer(c)">
                                <span class="truncate" x-text="c.name"></span><span class="shrink-0 text-xs text-muted" x-text="c.code"></span>
                            </button></li>
                        </template>
                        <li x-show="!customerMatches().length" class="px-3 py-2 text-xs text-muted">{{ __('Müştəri tapılmadı') }}</li>
                    </ul>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="o-manager">{{ __('Satış nümayəndəsi') }}</label>
                    <select id="o-manager" name="manager_id" class="select" x-model.number="manager_id" x-ref="manager" @disabled($managerLocked)>
                        <option value="">—</option>
                        @foreach ($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                    </select>
                    <div class="mt-1 text-xs text-muted" x-show="customer() && customer().manager_id && customer().manager_id !== manager_id" x-cloak>{{ __('Müştərinin öz nümayəndəsindən fərqlidir.') }}</div>
                </div>

                <div class="sm:col-span-2 lg:col-span-4">
                    <label class="label" for="o-note">{{ __('Əlavə qeydlər') }}</label>
                    <textarea id="o-note" name="note" rows="2" maxlength="2000" class="input h-auto py-2">{{ $values['note'] }}</textarea>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head flex-wrap">
                <div>
                    <h2 class="card-title">{{ __('Məhsullar') }}</h2>
                    <div class="card-sub">{{ __('Qiymət malın satış qiymətindən gəlir, dəyişmək olar. Rezerv stok = seçilmiş anbarın real stoku − açıq sifarişlərdə rezerv.') }}</div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn btn-sm" @click="addLine()"><x-icon name="plus" size="14"/> {{ __('Məhsul əlavə et') }}</button>
                    <button type="button" class="btn btn-sm btn-primary" x-show="!applyCampaigns" @click="giveCampaigns()" :disabled="!customer_id || !lines.some((l) => l.product_id)"
                            title="{{ __('Müştəriyə və mallara uyğun kampaniyaları tətbiq et') }}"><x-icon name="tag" size="14"/> {{ __('Kampaniya ver') }}</button>
                    <button type="button" class="btn btn-sm" x-show="applyCampaigns" x-cloak @click="removeCampaigns()"><x-icon name="x" size="14"/> {{ __('Kampaniyanı ləğv et') }}</button>
                </div>
            </div>
            <div class="overflow-x-auto lg:overflow-visible">
                <table class="w-full min-w-[860px] text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs uppercase text-muted">
                            <th class="w-8 px-3 py-2">#</th>
                            <th class="min-w-[260px] px-3 py-2">{{ __('Mal') }}</th>
                            <th class="w-24 px-3 py-2 text-right" title="{{ __('Real stok − açıq sifarişlərdə rezerv') }}">{{ __('Rezerv stok') }}</th>
                            <th class="w-40 px-3 py-2">{{ __('Tip') }}</th>
                            <th class="w-24 px-3 py-2 text-right">{{ __('Miqdar') }}</th>
                            <th class="w-28 px-3 py-2 text-right">{{ __('Qiymət') }}</th>
                            <th class="w-24 px-3 py-2 text-right">{{ __('Endirim') }} %</th>
                            <th class="w-32 px-3 py-2 text-right">{{ __('Yekun') }}</th>
                            <th class="w-10 px-2 py-2"></th>
                        </tr>
                    </thead>
                    <template x-for="(l, i) in lines" :key="l.key">
                        <tbody class="border-b border-line">
                            <tr class="align-top">
                                <td class="px-3 py-2 pt-4 text-muted" x-text="i + 1"></td>
                                <td class="relative px-3 py-2" @click.outside="l.open = false">
                                    <input type="hidden" :name="`lines[${i}][product_id]`" :value="l.product_id">
                                    <input data-product-input class="input" autocomplete="off" required placeholder="{{ __('Kod və ya ad ilə axtar…') }}" :aria-label="'{{ __('Mal') }} ' + (i + 1)"
                                           x-model="l.query" @focus="l.open = true" @input="l.open = true; l.product_id = null" @keydown.escape="l.open = false"
                                           @keydown.enter.prevent="productMatches(l)[0] && pickProduct(l, productMatches(l)[0])">
                                    <ul x-show="l.open" x-cloak class="card relative z-30 mt-1 max-h-72 w-full overflow-auto py-1 lg:absolute lg:w-[min(560px,90vw)]">
                                        <template x-for="p in productMatches(l)" :key="p.id">
                                            <li><button type="button" class="grid w-full grid-cols-[90px_minmax(0,1fr)_auto] items-center gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="pickProduct(l, p)">
                                                <span class="font-mono text-xs text-muted" x-text="p.code"></span>
                                                <span class="truncate" x-text="p.name"></span>
                                                <span class="text-xs" :class="stockOf(p.id) > 0 ? 'text-muted' : 'neg'" x-text="'{{ __('rezerv') }} ' + stockOf(p.id)"></span>
                                            </button></li>
                                        </template>
                                        <li x-show="!productMatches(l).length" class="px-3 py-2 text-xs text-muted">{{ __('Mal tapılmadı') }}</li>
                                    </ul>
                                </td>
                                <td class="px-3 py-2 pt-4 text-right tabular-nums" :class="l.product_id && stockOf(l.product_id) < Number(l.qty) ? 'neg font-semibold' : 'text-muted'"
                                    :title="l.product_id && stockOf(l.product_id) < Number(l.qty) ? '{{ __('Anbarda kifayət qədər qalıq yoxdur') }}' : ''"
                                    x-text="l.product_id ? stockOf(l.product_id) : '—'"></td>
                                <td class="px-3 py-2">
                                    <select class="select" :name="`lines[${i}][op_type]`" x-model="l.op_type" @change="if (l.op_type !== 'sale') l.price = 0" :aria-label="'{{ __('Tip') }} ' + (i + 1)">
                                        <template x-for="(label, key) in opTypes" :key="key"><option :value="key" x-text="label" :selected="key === l.op_type"></option></template>
                                    </select>
                                </td>
                                <td class="px-3 py-2"><input type="number" step="any" min="0.0001" required class="input text-right" :name="`lines[${i}][qty]`" x-model="l.qty" :aria-label="'{{ __('Miqdar') }} ' + (i + 1)"></td>
                                <td class="px-3 py-2"><input type="number" step="0.01" min="0" required class="input text-right" :name="`lines[${i}][price]`" x-model="l.price" :aria-label="'{{ __('Qiymət') }} ' + (i + 1)"></td>
                                <td class="px-3 py-2"><input type="number" step="0.01" min="0" max="100" class="input text-right" :name="`lines[${i}][discount]`" x-model="l.discount" :aria-label="'{{ __('Endirim') }} ' + (i + 1)"></td>
                                <td class="px-3 py-2 pt-4 text-right font-semibold tabular-nums">
                                    <span x-text="money(final(l, i))"></span>
                                    <div class="text-xs font-normal text-muted line-through" x-show="campaignDiscount(i) > 0" x-cloak x-text="money(net(l))"></div>
                                </td>
                                <td class="px-2 py-2"><button type="button" class="btn btn-sm btn-danger px-2" @click="removeLine(i)" title="{{ __('Sətri sil') }}" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" size="14"/></button></td>
                            </tr>
                            {{-- tətbiq olunmuş kampaniya — sətrin altında --}}
                            <template x-for="d in (lineResult(i)?.discounts ?? [])" :key="d.campaign_id">
                                <tr class="campaign-row text-xs">
                                    <td></td>
                                    <td colspan="5" class="px-3 pb-2">
                                        <span class="inline-flex flex-wrap items-center gap-2">
                                            <span aria-hidden="true">↳</span>
                                            <span class="badge badge-primary"><x-icon name="tag" size="11"/> <span x-text="d.code"></span></span>
                                            <span x-text="d.name"></span>
                                        </span>
                                    </td>
                                    <td class="px-3 pb-2 text-right tabular-nums" x-text="'−' + d.rate.toFixed(2).replace('.', ',') + ' %'"></td>
                                    <td class="px-3 pb-2 text-right font-semibold tabular-nums pos" x-text="'−' + money(d.amount)"></td>
                                    <td class="px-2 pb-2"><button type="button" class="btn btn-sm px-2" @click="toggleCampaign(d.campaign_id)" title="{{ __('Bu kampaniyanı çıxar') }}" aria-label="{{ __('Bu kampaniyanı çıxar') }}"><x-icon name="x" size="12"/></button></td>
                                </tr>
                            </template>
                            <template x-for="g in giftsOf(i)" :key="'g' + g.campaign_id">
                                <tr class="campaign-row text-xs">
                                    <td></td>
                                    <td colspan="5" class="px-3 pb-2">
                                        <span class="inline-flex flex-wrap items-center gap-2">
                                            <span aria-hidden="true">↳</span>
                                            <span class="badge badge-primary"><x-icon name="tag" size="11"/> <span x-text="g.code"></span></span>
                                            <span>{{ __('Hədiyyə') }}:</span>
                                            <span class="font-semibold" x-text="g.product_code + ' — ' + g.product_name"></span>
                                        </span>
                                    </td>
                                    <td class="px-3 pb-2 text-right font-semibold tabular-nums" x-text="qtyFmt(g.qty) + ' {{ __('əd.') }}'"></td>
                                    <td class="px-3 pb-2 text-right tabular-nums text-muted">{{ __('pulsuz') }}</td>
                                    <td class="px-2 pb-2"><button type="button" class="btn btn-sm px-2" @click="toggleCampaign(g.campaign_id)" title="{{ __('Bu kampaniyanı çıxar') }}" aria-label="{{ __('Bu kampaniyanı çıxar') }}"><x-icon name="x" size="12"/></button></td>
                                </tr>
                            </template>
                        </tbody>
                    </template>
                    <tfoot class="text-sm">
                        <tr><td colspan="7" class="px-3 pt-3 text-right text-muted">{{ __('Məbləğ (endirimsiz)') }}</td><td class="px-3 pt-3 text-right tabular-nums" x-text="money(sum((l) => gross(l)))"></td><td></td></tr>
                        <tr><td colspan="7" class="px-3 text-right text-muted">{{ __('Endirim') }}</td><td class="px-3 text-right tabular-nums" x-text="money(sum((l) => gross(l) - net(l)))"></td><td></td></tr>
                        <tr x-show="applyCampaigns" x-cloak><td colspan="7" class="px-3 text-right text-muted">{{ __('Kampaniya endirimi') }}</td><td class="px-3 text-right tabular-nums pos" x-text="'−' + money(sum((l, i) => campaignDiscount(i)))"></td><td></td></tr>
                        <tr><td colspan="7" class="px-3 pb-3 text-right font-semibold">{{ __('Yekun') }}</td><td class="px-3 pb-3 text-right text-base font-semibold tabular-nums" x-text="money(sum((l, i) => final(l, i)))"></td><td></td></tr>
                    </tfoot>
                </table>
            </div>

            {{-- kampaniyaların vəziyyəti: tətbiq olunan / olunmayan (səbəbi ilə) --}}
            <div class="border-t border-line px-4 py-3 text-sm" x-show="applyCampaigns" x-cloak>
                <div class="mb-2 flex items-center gap-2">
                    <span class="label mb-0">{{ __('Uyğun kampaniyalar') }}</span>
                    <span class="text-xs text-muted" x-show="campaignLoading">{{ __('Hesablanır…') }}</span>
                    <span class="text-xs neg" x-show="campaignError" x-text="campaignError"></span>
                </div>
                <div class="text-xs text-muted" x-show="campaignResult && !campaignResult.campaigns.length" x-text="@js($config['text']['none'])"></div>
                <ul class="flex flex-col gap-1.5">
                    <template x-for="cm in (campaignResult?.campaigns ?? [])" :key="cm.id">
                        <li class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="badge" :class="cm.applied ? 'badge-primary' : ''" x-text="cm.code"></span>
                            <span x-text="cm.name"></span>
                            <span class="pos" x-show="cm.applied">✓ {{ __('tətbiq olundu') }}</span>
                            <span class="text-muted" x-show="!cm.applied" x-text="'— ' + cm.reason"></span>
                            <button type="button" class="ml-auto text-xs underline" x-show="excluded.includes(cm.id)" @click="toggleCampaign(cm.id)">{{ __('Geri qaytar') }}</button>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line p-4">
                <button type="button" class="btn btn-sm" @click="addLine()"><x-icon name="plus" size="14"/> {{ __('Məhsul əlavə et') }}</button>
                <div class="flex items-center gap-2">
                    <a href="{{ $order ? route('orders.show', $order) : route('orders.index') }}" class="btn">{{ __('Ləğv et') }}</a>
                    <button class="btn" :disabled="!customer_id || lines.some((l) => !l.product_id) || campaignLoading">{{ $order ? __('Yadda saxla') : __('Sifarişi yarat') }}</button>
                    <button name="then" value="send" class="btn" :disabled="!customer_id || lines.some((l) => !l.product_id) || campaignLoading" title="{{ __('Sifarişi saxla və təhvil-təslim sənədi yarat') }}"><x-icon name="send" size="14"/> {{ __('Sifarişi göndər') }}</button>
                    <button name="then" value="invoice" class="btn btn-primary" :disabled="!customer_id || lines.some((l) => !l.product_id) || campaignLoading" title="{{ __('Sifarişi saxla, təhvil-təslim və satış fakturası yarat') }}"><x-icon name="invoice" size="14"/> {{ __('Fakturala') }}</button>
                </div>
            </div>
        </section>
    </form>
</x-layouts.app>
