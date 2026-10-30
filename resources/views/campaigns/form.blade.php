@php
    $kindHelp = [
        'simple_discount' => __('Kampaniya mallarına sabit faiz endirimi (mala görə ayrıca faiz də yazmaq olar).'),
        'qty_threshold_discount' => __('Kampaniya mallarından ümumi miqdar həddə çatanda endirim.'),
        'amount_threshold_discount' => __('Kampaniya mallarının məbləği həddə çatanda endirim.'),
        'tiered_discount' => __('Miqdar və ya məbləğ pillələrinə görə fərqli faiz (və ya tavan).'),
        'invoice_bottom_discount' => __('Bütün qaimə üzrə faiz endirimi (xaric mallar istisna).'),
        'qty_gift' => __('Hər N ədədə M ədəd hədiyyə və ya pilləli hədiyyə.'),
        'amount_gift' => __('Hər N manata M ədəd hədiyyə və ya pilləli hədiyyə.'),
        'max_discount_cap' => __('Sətirdəki bütün endirimləri brütün göstərilən faizinə qədər tamamlayır.'),
        'target_price' => __('Vahid qiyməti mal üzrə hədəf qiymətə endirir.'),
    ];
    $config = [
        'values' => $values,
        'products' => $products,
        'customers' => $customers,
    ];
@endphp
<x-layouts.app :title="$title">
    <script>
        function campaignForm(config) {
            const v = config.values;
            const products = new Map(config.products.map((p) => [p.id, p]));
            const customers = new Map(config.customers.map((c) => [c.id, c]));
            const norm = (s) => String(s ?? '').toLocaleLowerCase('az').replace(/\s+/g, ' ').trim();
            const search = (list, q, picked, fields) => {
                const n = norm(q);
                return list.filter((x) => !picked.includes(x.id) && (!n || norm(fields(x)).includes(n))).slice(0, 30);
            };

            return {
                kind: v.kind,
                calc_basis: v.calc_basis,
                tier_calc: v.tier_calc,
                gift_mode: v.gift_mode,
                product_scope: v.product_scope,
                customer_scope: v.customer_scope,
                tiers: (v.tiers.length ? v.tiers : [{ from: '', to: '', value: '' }]).map((t) => ({ key: Math.random(), from: t.from ?? '', to: t.to ?? '', value: t.value ?? '' })),
                productRows: v.products.map((r) => ({ id: Number(r.id), rate: r.rate ?? '', price: r.price ?? '' })).filter((r) => products.has(r.id)),
                excludedProducts: v.excluded_products.filter((id) => products.has(id)),
                customerIds: v.customers.filter((id) => customers.has(id)),
                excludedCustomers: v.customer_excludes.filter((id) => customers.has(id)),
                giftProduct: v.gift_product_id,
                q: { product: '', excluded: '', customer: '', customerEx: '', gift: '' },
                open: { product: false, excluded: false, customer: false, customerEx: false, gift: false },

                isGift() { return ['qty_gift', 'amount_gift'].includes(this.kind); },
                isTiered() { return this.kind === 'tiered_discount' || (this.isGift() && this.gift_mode !== 'proportional'); },
                needsRate() { return ['simple_discount', 'qty_threshold_discount', 'amount_threshold_discount', 'invoice_bottom_discount', 'max_discount_cap'].includes(this.kind); },
                needsThreshold() { return ['qty_threshold_discount', 'amount_threshold_discount'].includes(this.kind); },
                basis() {
                    if (['amount_threshold_discount', 'amount_gift', 'invoice_bottom_discount'].includes(this.kind)) return 'amount';
                    if (this.kind === 'tiered_discount') return this.calc_basis;
                    return 'qty';
                },
                unit() { return this.basis() === 'amount' ? '₼' : '{{ __('əd.') }}'; },
                tierValueLabel() { return this.isGift() ? '{{ __('Hədiyyə miqdarı') }}' : (this.tier_calc === 'cap' ? '{{ __('Tavan %') }}' : '{{ __('Endirim %') }}'); },

                product(id) { return products.get(id); },
                customer(id) { return customers.get(id); },
                productLabel(id) { const p = products.get(id); return p ? p.code + ' — ' + p.name : ''; },
                customerLabel(id) { const c = customers.get(id); return c ? c.name + ' · ' + c.code : ''; },
                productMatches(key, picked) { return search(config.products, this.q[key], picked, (p) => p.code + ' ' + p.name); },
                customerMatches(key, picked) { return search(config.customers, this.q[key], picked, (c) => c.code + ' ' + c.name); },
                addProduct(p) { this.productRows.push({ id: p.id, rate: '', price: '' }); this.q.product = ''; },
                addTier() { const last = this.tiers[this.tiers.length - 1]; this.tiers.push({ key: Math.random(), from: last?.to ?? '', to: '', value: '' }); },
                removeTier(i) { this.tiers.splice(i, 1); if (!this.tiers.length) this.addTier(); },
            };
        }
    </script>

    <form method="POST" action="{{ $campaign ? route('campaigns.update', $campaign) : route('campaigns.store') }}" class="flex flex-col gap-4" x-data="campaignForm(@js($config))">
        @csrf
        @if ($campaign) @method('PUT') @endif

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $campaign ? route('campaigns.show', $campaign) : route('campaigns.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
            @unless (auth()->user()->can('sales.manage'))<span class="text-xs text-muted">{{ __('Kampaniya admin təsdiqindən sonra aktiv olacaq.') }}</span>@endunless
        </div>

        {{-- 1. Əsas məlumat --}}
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Əsas məlumat') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2"><label class="label" for="k-name">{{ __('Ad') }}</label><input id="k-name" name="name" required maxlength="200" class="input" value="{{ $values['name'] }}"></div>
                <div><label class="label" for="k-code">{{ __('Kod') }}</label><input id="k-code" name="code" maxlength="25" class="input font-mono" value="{{ $values['code'] }}" placeholder="{{ __('avtomatik') }}"></div>
                <div class="flex items-end"><label class="flex h-9 items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4" @checked($values['is_active'])> {{ __('Aktiv') }}</label></div>
                <div><label class="label" for="k-begin">{{ __('Başlama tarixi') }}</label><input id="k-begin" type="date" name="begin_date" required class="input" value="{{ $values['begin_date'] }}"></div>
                <div><label class="label" for="k-end">{{ __('Bitmə tarixi') }}</label><input id="k-end" type="date" name="end_date" required class="input" value="{{ $values['end_date'] }}"></div>
                <div><label class="label" for="k-priority">{{ __('Prioritet') }}</label><input id="k-priority" type="number" min="0" max="999" name="priority" class="input" value="{{ $values['priority'] }}" title="{{ __('Kiçik rəqəm əvvəl tətbiq olunur') }}"></div>
                <div><label class="label" for="k-group">{{ __('Eksklüziv qrup') }}</label><input id="k-group" name="priority_group" maxlength="30" class="input" value="{{ $values['priority_group'] }}" placeholder="{{ __('məs. YAY') }}" title="{{ __('Eyni qrupdan sifarişə yalnız bir kampaniya tətbiq olunur') }}"></div>
                <div><label class="label" for="k-limit">{{ __('Müştəri üzrə limit') }}</label><input id="k-limit" type="number" min="0" name="apply_limit" class="input" value="{{ $values['apply_limit'] }}" title="{{ __('Bir müştəriyə neçə sifarişdə tətbiq olunsun; 0 = limitsiz') }}"></div>
                <div><label class="label" for="k-budget">{{ __('Təxmini büdcə') }}</label><input id="k-budget" type="number" step="0.01" min="0" name="estimated_budget" class="input" value="{{ $values['estimated_budget'] }}"></div>
                <div class="sm:col-span-2"><label class="label" for="k-desc">{{ __('Təsvir') }}</label><textarea id="k-desc" name="description" rows="2" class="input h-auto py-2">{{ $values['description'] }}</textarea></div>
            </div>
        </section>

        {{-- 2. Növ və şərt --}}
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Kampaniya növü və şərt') }}</h2></div>
            <div class="flex flex-col gap-4 p-4">
                <fieldset class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <legend class="sr-only">{{ __('Kampaniya növü') }}</legend>
                    @foreach ($kinds as $key => $label)
                        <label class="flex cursor-pointer gap-2 rounded-lg border p-3 text-sm" :class="kind === '{{ $key }}' ? 'border-[var(--primary)] bg-[var(--primary-soft)]' : 'border-line'">
                            <input type="radio" name="kind" value="{{ $key }}" x-model="kind" class="mt-0.5 size-4 shrink-0">
                            <span><span class="font-semibold">{{ $label }}</span><span class="mt-0.5 block text-xs text-muted">{{ $kindHelp[$key] }}</span></span>
                        </label>
                    @endforeach
                </fieldset>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div x-show="needsRate()"><label class="label" for="k-rate">{{ __('Endirim faizi') }} %</label><input id="k-rate" type="number" step="0.01" min="0" max="100" name="discount_rate" class="input" value="{{ $values['discount_rate'] }}" :disabled="!needsRate()"></div>
                    <div x-show="needsThreshold() || (isGift() && gift_mode === 'proportional')">
                        <label class="label" for="k-threshold"><span x-text="isGift() ? '{{ __('Minimum həcm') }}' : '{{ __('Hədd') }}'"></span> (<span x-text="unit()"></span>)</label>
                        <input id="k-threshold" type="number" step="0.01" min="0" name="threshold_value" class="input" value="{{ $values['threshold_value'] }}" :disabled="!(needsThreshold() || (isGift() && gift_mode === 'proportional'))">
                    </div>
                    <div x-show="kind === 'tiered_discount'">
                        <label class="label" for="k-basis">{{ __('Pillə bazası') }}</label>
                        <select id="k-basis" name="calc_basis" class="select" x-model="calc_basis" :disabled="kind !== 'tiered_discount'">
                            <option value="qty">{{ __('Miqdar') }}</option><option value="amount">{{ __('Məbləğ') }}</option>
                        </select>
                    </div>
                    <div x-show="kind === 'tiered_discount'">
                        <label class="label" for="k-calc">{{ __('Hesablama') }}</label>
                        <select id="k-calc" name="tier_calc" class="select" x-model="tier_calc" :disabled="kind !== 'tiered_discount'">
                            <option value="percent">{{ __('Faiz endirimi') }}</option><option value="cap">{{ __('Tavan (ümumi endirim % qədər)') }}</option>
                        </select>
                    </div>
                    <div x-show="kind === 'tiered_discount'">
                        <label class="label" for="k-scope">{{ __('Pillə hesablanır') }}</label>
                        <select id="k-scope" name="tier_scope" class="select" :disabled="kind !== 'tiered_discount'">
                            <option value="products" @selected($values['tier_scope'] === 'products')>{{ __('Kampaniya malları üzrə') }}</option>
                            <option value="document" @selected($values['tier_scope'] === 'document')>{{ __('Bütün sənəd üzrə') }}</option>
                        </select>
                    </div>
                    <div x-show="isGift()">
                        <label class="label" for="k-giftmode">{{ __('Hədiyyə rejimi') }}</label>
                        <select id="k-giftmode" name="gift_mode" class="select" x-model="gift_mode" :disabled="!isGift()">
                            <option value="proportional">{{ __('Proporsional: hər N-ə M') }}</option>
                            <option value="tiered">{{ __('Pilləli: sabit miqdar') }}</option>
                            <option value="tiered_multiple">{{ __('Pilləli-çoxqat') }}</option>
                        </select>
                    </div>
                    <div x-show="isGift() && gift_mode === 'proportional'"><label class="label" for="k-div">{{ __('Hər N') }} (<span x-text="unit()"></span>)</label><input id="k-div" type="number" step="0.01" min="0" name="gift_divisor" class="input" value="{{ $values['gift_divisor'] }}" :disabled="!(isGift() && gift_mode === 'proportional')"></div>
                    <div x-show="isGift() && gift_mode === 'proportional'"><label class="label" for="k-gqty">{{ __('M ədəd hədiyyə') }}</label><input id="k-gqty" type="number" step="0.01" min="0" name="gift_qty" class="input" value="{{ $values['gift_qty'] }}" :disabled="!(isGift() && gift_mode === 'proportional')"></div>
                    <div x-show="basis() === 'amount'"><label class="label" for="k-vat">{{ __('ƏDV əmsalı') }}</label>
                        <select id="k-vat" name="vat_multiplier" class="select">
                            @foreach (['1' => '1,00 — '.__('ƏDV-siz'), '1.18' => '1,18 — '.__('ƏDV daxil')] as $val => $label)<option value="{{ $val }}" @selected((float) $values['vat_multiplier'] === (float) $val)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <template x-if="basis() !== 'amount'"><input type="hidden" name="vat_multiplier" value="{{ $values['vat_multiplier'] }}"></template>
                    <div x-show="!isGift() && kind !== 'target_price'">
                        <label class="label" for="k-base">{{ __('Endirim bazası') }}</label>
                        <select id="k-base" name="formula_base" class="select">
                            <option value="P3" @selected($values['formula_base'] === 'P3')>{{ __('Əvvəlki endirimlərdən sonra (P3)') }}</option>
                            <option value="P1" @selected($values['formula_base'] === 'P1')>{{ __('Brüt məbləğ (P1)') }}</option>
                        </select>
                    </div>
                    <template x-if="isGift() || kind === 'target_price'"><input type="hidden" name="formula_base" value="P3"></template>
                </div>

                {{-- hədiyyə malı --}}
                <div x-show="isGift()" class="relative max-w-xl" @click.outside="open.gift = false">
                    <label class="label" for="k-gift">{{ __('Hədiyyə malı') }}</label>
                    <input type="hidden" name="gift_product_id" :value="isGift() ? giftProduct : ''">
                    <input id="k-gift" class="input" autocomplete="off" placeholder="{{ __('Kod və ya ad ilə axtar…') }}" :value="giftProduct && !open.gift ? productLabel(giftProduct) : q.gift"
                           @focus="open.gift = true; q.gift = ''" @input="q.gift = $event.target.value; open.gift = true">
                    <ul x-show="open.gift" x-cloak class="card absolute z-30 mt-1 max-h-64 w-full overflow-auto py-1 text-sm">
                        <template x-for="p in productMatches('gift', [])" :key="p.id">
                            <li><button type="button" class="flex w-full gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="giftProduct = p.id; open.gift = false"><span class="font-mono text-xs text-muted" x-text="p.code"></span><span x-text="p.name"></span></button></li>
                        </template>
                    </ul>
                </div>

                {{-- pillələr --}}
                <div x-show="isTiered()" class="max-w-2xl">
                    <div class="label">{{ __('Pillələr') }} (<span x-text="unit()"></span>)</div>
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-muted"><th class="pb-1 pr-2">{{ __('Başlanğıc (≥)') }}</th><th class="pb-1 pr-2">{{ __('Son (<), boş = ∞') }}</th><th class="pb-1 pr-2" x-text="tierValueLabel()"></th><th></th></tr></thead>
                        <tbody>
                            <template x-for="(t, i) in tiers" :key="t.key">
                                <tr>
                                    <td class="pb-2 pr-2"><input type="number" step="any" min="0" class="input" :name="`tiers[${i}][from]`" x-model="t.from" :disabled="!isTiered()" :aria-label="'{{ __('Pillə: başlanğıc') }} ' + (i + 1)"></td>
                                    <td class="pb-2 pr-2"><input type="number" step="any" min="0" class="input" :name="`tiers[${i}][to]`" x-model="t.to" :disabled="!isTiered()" :aria-label="'{{ __('Pillə: son') }} ' + (i + 1)"></td>
                                    <td class="pb-2 pr-2"><input type="number" step="any" min="0" class="input" :name="`tiers[${i}][value]`" x-model="t.value" :disabled="!isTiered()" :aria-label="'{{ __('Pillə dəyəri') }} ' + (i + 1)"></td>
                                    <td class="pb-2"><button type="button" class="btn btn-sm btn-danger px-2" @click="removeTier(i)" aria-label="{{ __('Pilləni sil') }}"><x-icon name="trash" size="14"/></button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-sm" @click="addTier()"><x-icon name="plus" size="14"/> {{ __('Pillə əlavə et') }}</button>
                </div>
            </div>
        </section>

        {{-- 3. Mallar --}}
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Kampaniya malları') }}</h2></div>
            <div class="flex flex-col gap-3 p-4">
                <div class="flex flex-wrap gap-3 text-sm">
                    @foreach (['all' => __('Bütün mallar'), 'products' => __('Seçilmiş mallar'), 'groups' => __('Mal qrupları'), 'categories' => __('Kateqoriyalar'), 'series' => __('Seriyalar')] as $key => $label)
                        <label class="flex items-center gap-1.5"><input type="radio" name="product_scope" value="{{ $key }}" x-model="product_scope" class="size-4"> {{ $label }}</label>
                    @endforeach
                </div>

                <div x-show="product_scope === 'products'" class="flex flex-col gap-2">
                    <div class="relative max-w-xl" @click.outside="open.product = false">
                        <input class="input" autocomplete="off" placeholder="{{ __('Mal əlavə et: kod və ya ad…') }}" aria-label="{{ __('Mal əlavə et') }}" x-model="q.product" @focus="open.product = true" @input="open.product = true">
                        <ul x-show="open.product" x-cloak class="card absolute z-30 mt-1 max-h-64 w-full overflow-auto py-1 text-sm">
                            <template x-for="p in productMatches('product', productRows.map((r) => r.id))" :key="p.id">
                                <li><button type="button" class="flex w-full gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="addProduct(p)"><span class="font-mono text-xs text-muted" x-text="p.code"></span><span x-text="p.name"></span></button></li>
                            </template>
                        </ul>
                    </div>
                    <table class="w-full max-w-3xl text-sm" x-show="productRows.length">
                        <thead><tr class="border-b border-line text-left text-xs text-muted">
                            <th class="py-1">{{ __('Mal') }}</th>
                            <th class="w-32 py-1" x-show="kind === 'simple_discount'">{{ __('Ayrıca faiz %') }}</th>
                            <th class="w-32 py-1" x-show="kind === 'target_price'">{{ __('Hədəf qiymət') }}</th>
                            <th class="w-10"></th>
                        </tr></thead>
                        <tbody>
                            <template x-for="(r, i) in productRows" :key="r.id">
                                <tr class="border-b border-line">
                                    <td class="py-1.5 pr-2"><input type="hidden" :name="`products[${i}][id]`" :value="r.id" :disabled="product_scope !== 'products'"><span x-text="productLabel(r.id)"></span></td>
                                    <td class="py-1.5 pr-2" x-show="kind === 'simple_discount'"><input type="number" step="0.01" min="0" max="100" class="input h-8" :name="`products[${i}][rate]`" x-model="r.rate" :disabled="product_scope !== 'products' || kind !== 'simple_discount'" :aria-label="'{{ __('Ayrıca faiz %') }}'"></td>
                                    <td class="py-1.5 pr-2" x-show="kind === 'target_price'"><input type="number" step="0.01" min="0" class="input h-8" :name="`products[${i}][price]`" x-model="r.price" :disabled="product_scope !== 'products' || kind !== 'target_price'" :aria-label="'{{ __('Hədəf qiymət') }}'"></td>
                                    <td class="py-1.5"><button type="button" class="btn btn-sm btn-danger px-2" @click="productRows.splice(i, 1)" aria-label="{{ __('Sətri sil') }}"><x-icon name="x" size="12"/></button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                @foreach (['groups' => $groups, 'categories' => $categories] as $field => $list)
                    <div x-show="product_scope === '{{ $field }}'" class="grid max-h-64 grid-cols-1 gap-1 overflow-auto rounded-lg border border-line p-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        @forelse ($list as $item)
                            <label class="flex items-center gap-2"><input type="checkbox" name="{{ $field }}[]" value="{{ $item->id }}" class="size-4" @checked(in_array($item->id, $values[$field], true)) :disabled="product_scope !== '{{ $field }}'"> {{ $item->name }}</label>
                        @empty
                            <span class="text-xs text-muted">{{ __('Siyahı boşdur') }}</span>
                        @endforelse
                    </div>
                @endforeach
                <div x-show="product_scope === 'series'" class="grid max-h-64 grid-cols-1 gap-1 overflow-auto rounded-lg border border-line p-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($seriesList as $s)
                        <label class="flex items-center gap-2"><input type="checkbox" name="series[]" value="{{ $s }}" class="size-4" @checked(in_array($s, $values['series'], true)) :disabled="product_scope !== 'series'"> {{ $s }}</label>
                    @empty
                        <span class="text-xs text-muted">{{ __('Siyahı boşdur') }}</span>
                    @endforelse
                </div>

                <div class="relative max-w-xl" @click.outside="open.excluded = false">
                    <label class="label" for="k-exprod">{{ __('Xaric mallar') }}</label>
                    <template x-for="id in excludedProducts" :key="id"><input type="hidden" name="excluded_products[]" :value="id"></template>
                    <div class="mb-1 flex flex-wrap gap-1" x-show="excludedProducts.length">
                        <template x-for="(id, i) in excludedProducts" :key="id"><button type="button" class="badge" @click="excludedProducts.splice(i, 1)"><span x-text="product(id)?.code"></span> ×</button></template>
                    </div>
                    <input id="k-exprod" class="input" autocomplete="off" placeholder="{{ __('Kod və ya ad ilə axtar…') }}" x-model="q.excluded" @focus="open.excluded = true" @input="open.excluded = true">
                    <ul x-show="open.excluded" x-cloak class="card absolute z-30 mt-1 max-h-64 w-full overflow-auto py-1 text-sm">
                        <template x-for="p in productMatches('excluded', excludedProducts)" :key="p.id">
                            <li><button type="button" class="flex w-full gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="excludedProducts.push(p.id); q.excluded = ''"><span class="font-mono text-xs text-muted" x-text="p.code"></span><span x-text="p.name"></span></button></li>
                        </template>
                    </ul>
                </div>
            </div>
        </section>

        {{-- 4. Müştərilər --}}
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Kampaniya müştəriləri') }}</h2></div>
            <div class="flex flex-col gap-3 p-4">
                <div class="flex flex-wrap gap-3 text-sm">
                    @foreach (['all' => __('Bütün müştərilər'), 'customers' => __('Seçilmiş müştərilər'), 'managers' => __('Satış nümayəndəsinin müştəriləri')] as $key => $label)
                        <label class="flex items-center gap-1.5"><input type="radio" name="customer_scope" value="{{ $key }}" x-model="customer_scope" class="size-4"> {{ $label }}</label>
                    @endforeach
                </div>
                <div x-show="customer_scope === 'customers'" class="relative max-w-xl" @click.outside="open.customer = false">
                    <template x-for="id in customerIds" :key="id"><input type="hidden" name="customers[]" :value="id" :disabled="customer_scope !== 'customers'"></template>
                    <div class="mb-1 flex flex-wrap gap-1" x-show="customerIds.length">
                        <template x-for="(id, i) in customerIds" :key="id"><button type="button" class="badge badge-primary" @click="customerIds.splice(i, 1)"><span x-text="customer(id)?.name"></span> ×</button></template>
                    </div>
                    <input class="input" autocomplete="off" placeholder="{{ __('Müştəri əlavə et: ad və ya kod…') }}" aria-label="{{ __('Müştəri əlavə et') }}" x-model="q.customer" @focus="open.customer = true" @input="open.customer = true">
                    <ul x-show="open.customer" x-cloak class="card absolute z-30 mt-1 max-h-64 w-full overflow-auto py-1 text-sm">
                        <template x-for="c in customerMatches('customer', customerIds)" :key="c.id">
                            <li><button type="button" class="flex w-full justify-between gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="customerIds.push(c.id); q.customer = ''"><span x-text="c.name"></span><span class="text-xs text-muted" x-text="c.code"></span></button></li>
                        </template>
                    </ul>
                </div>
                <div x-show="customer_scope === 'managers'" class="flex flex-wrap gap-3 text-sm">
                    @foreach ($managers as $m)
                        <label class="flex items-center gap-1.5"><input type="checkbox" name="managers[]" value="{{ $m->id }}" class="size-4" @checked(in_array($m->id, $values['managers'], true)) :disabled="customer_scope !== 'managers'"> {{ $m->name }}</label>
                    @endforeach
                </div>
                <div class="relative max-w-xl" @click.outside="open.customerEx = false">
                    <label class="label" for="k-excust">{{ __('Xaric müştərilər') }}</label>
                    <template x-for="id in excludedCustomers" :key="id"><input type="hidden" name="customer_excludes[]" :value="id"></template>
                    <div class="mb-1 flex flex-wrap gap-1" x-show="excludedCustomers.length">
                        <template x-for="(id, i) in excludedCustomers" :key="id"><button type="button" class="badge" @click="excludedCustomers.splice(i, 1)"><span x-text="customer(id)?.name"></span> ×</button></template>
                    </div>
                    <input id="k-excust" class="input" autocomplete="off" placeholder="{{ __('Ad və ya kod…') }}" x-model="q.customerEx" @focus="open.customerEx = true" @input="open.customerEx = true">
                    <ul x-show="open.customerEx" x-cloak class="card absolute z-30 mt-1 max-h-64 w-full overflow-auto py-1 text-sm">
                        <template x-for="c in customerMatches('customerEx', excludedCustomers)" :key="c.id">
                            <li><button type="button" class="flex w-full justify-between gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="excludedCustomers.push(c.id); q.customerEx = ''"><span x-text="c.name"></span><span class="text-xs text-muted" x-text="c.code"></span></button></li>
                        </template>
                    </ul>
                </div>
            </div>
        </section>

        <div class="flex justify-end gap-2">
            <a href="{{ $campaign ? route('campaigns.show', $campaign) : route('campaigns.index') }}" class="btn">{{ __('Ləğv et') }}</a>
            <button class="btn btn-primary">{{ $campaign ? __('Yadda saxla') : __('Kampaniyanı yarat') }}</button>
        </div>
    </form>
</x-layouts.app>
