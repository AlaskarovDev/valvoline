@php
    $v = $values;
    $config = ['units' => $v['units'], 'alternatives' => $v['alternatives'], 'products' => $allProducts, 'unitNames' => $lookups['units']->pluck('name', 'id'),
        'baseUnit' => (int) $v['base_unit_id'], 'vatMode' => $v['vat_mode'], 'saleUnit' => $v['sale_unit'], 'purchaseUnit' => $v['purchase_unit']];
    $select = function (string $name, string $label, $list, $selected, bool $required = false) {
        return view('products._select', compact('name', 'label', 'list', 'selected', 'required'))->render();
    };
@endphp
<x-layouts.app :title="$title">
    <script>
        function productForm(config) {
            const products = new Map(config.products.map((p) => [p.id, p]));
            const norm = (s) => String(s ?? '').toLocaleLowerCase('az').trim();
            return {
                units: config.units.map((u) => ({ key: Math.random(), unit_id: Number(u.unit_id) || '', factor: u.factor ?? '', barcode: u.barcode ?? '', weight_kg: u.weight_kg ?? '' })),
                baseUnit: config.baseUnit,
                vatMode: config.vatMode,
                saleUnit: config.saleUnit ? Number(config.saleUnit) : '',
                purchaseUnit: config.purchaseUnit ? Number(config.purchaseUnit) : '',
                alternatives: config.alternatives.filter((id) => products.has(id)),
                altQuery: '', altOpen: false,
                unitName(id) { return config.unitNames[id] ?? ''; },
                addUnit() { this.units.push({ key: Math.random(), unit_id: '', factor: '', barcode: '', weight_kg: '' }); },
                product(id) { return products.get(id); },
                altMatches() {
                    const q = norm(this.altQuery);
                    return config.products.filter((p) => !this.alternatives.includes(p.id) && (!q || norm(p.code + ' ' + p.name).includes(q))).slice(0, 30);
                },
            };
        }
    </script>

    <form method="POST" enctype="multipart/form-data" action="{{ $product ? route('products.update', $product) : route('products.store') }}" class="flex flex-col gap-4" x-data="productForm(@js($config))">
        @csrf
        @if ($product) @method('PUT') @endif
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $product ? route('products.show', $product) : route('products.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        </div>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Əsas məlumat') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="p-code">{{ __('Kod') }}</label><input id="p-code" name="code" required maxlength="64" class="input font-mono" value="{{ $v['code'] }}"></div>
                <div class="sm:col-span-2"><label class="label" for="p-name">{{ __('Ad') }}</label><input id="p-name" name="name" required maxlength="255" class="input" value="{{ $v['name'] }}"></div>
                <div><label class="label" for="p-short">{{ __('Qısa ad (qaimə üçün)') }}</label><input id="p-short" name="short_name" maxlength="100" class="input" value="{{ $v['short_name'] }}"></div>
                <div><label class="label" for="p-barcode">{{ __('Barkod (GTIN)') }}</label><input id="p-barcode" name="barcode" maxlength="64" class="input font-mono" value="{{ $v['barcode'] }}" placeholder="4770000000000"></div>
                <div class="sm:col-span-2"><label class="label" for="p-xbar">{{ __('Əlavə barkodlar') }}</label><input id="p-xbar" name="extra_barcodes" class="input font-mono" value="{{ $v['extra_barcodes'] }}" placeholder="{{ __('boşluq və ya vergüllə') }}"></div>
                <div class="flex items-end"><label class="flex h-9 items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4" @checked($v['is_active'])> {{ __('Aktiv (satışda)') }}</label></div>
                {!! $select('brand_id', __('Marka'), $lookups['brands'], $v['brand_id']) !!}
                {!! $select('manufacturer_id', __('İstehsalçı'), $lookups['manufacturers'], $v['manufacturer_id']) !!}
                {!! $select('product_class_id', __('Sinif'), $lookups['classes'], $v['product_class_id']) !!}
                {!! $select('category_id', __('Kateqoriya'), $lookups['categories'], $v['category_id']) !!}
                {!! $select('product_group_id', __('Məhsul qrupu'), $lookups['groups'], $v['product_group_id']) !!}
                <div><label class="label" for="p-series">{{ __('Seriya') }}</label><input id="p-series" name="series" maxlength="100" class="input" value="{{ $v['series'] }}"></div>
                <div><label class="label" for="p-visc">{{ __('Özlülük') }}</label><input id="p-visc" name="viscosity" maxlength="50" class="input" value="{{ $v['viscosity'] }}" placeholder="5W-30"></div>
                <div><label class="label" for="p-app">{{ __('Tətbiq sahəsi') }}</label><input id="p-app" name="application" maxlength="100" class="input" value="{{ $v['application'] }}" list="p-app-list">
                    <datalist id="p-app-list">@foreach ([__('Minik avtomobili'), __('Ağır texnika (dizel)'), __('Transmissiya'), __('Hidravlika'), __('Antifriz'), __('Sənaye'), __('Motosiklet'), __('Sürtkü')] as $a)<option value="{{ $a }}">@endforeach</datalist></div>
                <div class="sm:col-span-2"><label class="label" for="p-spec">{{ __('Spesifikasiya / təsdiqlər') }}</label><input id="p-spec" name="specifications" maxlength="255" class="input" value="{{ $v['specifications'] }}" placeholder="API SP, ACEA C3, MB 229.51"></div>
                <div><label class="label" for="p-origin">{{ __('Mənşə ölkəsi') }}</label><input id="p-origin" name="country_of_origin" maxlength="64" class="input" value="{{ $v['country_of_origin'] }}"></div>
                <div><label class="label" for="p-shelf">{{ __('Saxlanma müddəti, ay') }}</label><input id="p-shelf" type="number" min="0" name="shelf_life_months" class="input" value="{{ $v['shelf_life_months'] }}" placeholder="60"></div>
                <div class="sm:col-span-2 lg:col-span-4"><label class="label" for="p-desc">{{ __('Təsvir') }}</label><textarea id="p-desc" name="description" rows="2" class="input h-auto py-2">{{ $v['description'] }}</textarea></div>
                <div class="sm:col-span-2">
                    <label class="label" for="p-image">{{ __('Şəkil') }}</label>
                    <input id="p-image" type="file" name="image" accept="image/*" class="input py-1.5">
                    @if ($product?->image_path)<label class="mt-1 flex items-center gap-2 text-xs"><input type="checkbox" name="remove_image" value="1" class="size-4"> {{ __('Mövcud şəkli sil') }}</label>@endif
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ __('Vahidlər və qablaşdırma') }}</h2>
                    <div class="card-sub">{{ __('Stok əsas vahiddə saxlanılır. Digər vahidlər əsas vahidə çevrilir: məs. 1 qutu = 12 ədəd.') }}</div>
                </div>
                <button type="button" class="btn btn-sm" @click="addUnit()"><x-icon name="plus" size="14"/> {{ __('Vahid əlavə et') }}</button>
            </div>
            <div class="flex flex-col gap-3 p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="label" for="p-base">{{ __('Əsas vahid') }}</label>
                        <select id="p-base" name="base_unit_id" class="select" required x-model.number="baseUnit">
                            @foreach ($lookups['units'] as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                        </select>
                    </div>
                    <div><label class="label" for="p-vol">{{ __('Həcm, litr') }}</label><input id="p-vol" type="number" step="0.001" min="0" name="volume_l" class="input" value="{{ $v['volume_l'] }}"></div>
                    <div><label class="label" for="p-weight">{{ __('Çəki, kq') }}</label><input id="p-weight" type="number" step="0.001" min="0" name="weight_kg" class="input" value="{{ $v['weight_kg'] }}"></div>
                </div>
                <table class="w-full max-w-4xl text-sm" x-show="units.length">
                    <thead><tr class="text-left text-xs uppercase text-muted">
                        <th class="pb-1 pr-2">{{ __('Vahid') }}</th><th class="pb-1 pr-2">{{ __('Əmsal') }}</th><th class="pb-1 pr-2">{{ __('Barkod') }}</th><th class="pb-1 pr-2">{{ __('Çəki, kq') }}</th><th class="pb-1 pr-2">{{ __('Susmaya görə') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(u, i) in units" :key="u.key">
                            <tr class="align-top">
                                <td class="pb-2 pr-2">
                                    <select class="select" :name="`units[${i}][unit_id]`" x-model.number="u.unit_id" required :aria-label="'{{ __('Vahid') }} ' + (i + 1)">
                                        <option value="">—</option>
                                        @foreach ($lookups['units'] as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                                    </select>
                                </td>
                                <td class="pb-2 pr-2">
                                    <input type="number" step="any" min="0.0001" class="input" :name="`units[${i}][factor]`" x-model="u.factor" required :aria-label="'{{ __('Əmsal') }} ' + (i + 1)">
                                    <div class="mt-1 text-xs text-muted" x-show="u.unit_id && u.factor" x-text="'1 ' + unitName(u.unit_id) + ' = ' + u.factor + ' ' + unitName(baseUnit)"></div>
                                </td>
                                <td class="pb-2 pr-2"><input class="input font-mono" maxlength="64" :name="`units[${i}][barcode]`" x-model="u.barcode" :aria-label="'{{ __('Barkod') }} ' + (i + 1)"></td>
                                <td class="pb-2 pr-2"><input type="number" step="0.001" min="0" class="input" :name="`units[${i}][weight_kg]`" x-model="u.weight_kg" :aria-label="'{{ __('Çəki, kq') }} ' + (i + 1)"></td>
                                <td class="pb-2 pr-2 text-xs">
                                    <label class="flex items-center gap-1"><input type="radio" name="sale_unit" :value="u.unit_id" x-model.number="saleUnit" class="size-3.5"> {{ __('satış') }}</label>
                                    <label class="flex items-center gap-1"><input type="radio" name="purchase_unit" :value="u.unit_id" x-model.number="purchaseUnit" class="size-3.5"> {{ __('alış') }}</label>
                                </td>
                                <td class="pb-2"><button type="button" class="btn btn-sm btn-danger px-2" @click="units.splice(i, 1)" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" size="14"/></button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Vergi və e-qaimə') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="label" for="p-vatmode">{{ __('ƏDV kateqoriyası') }}</label>
                    <select id="p-vatmode" name="vat_mode" class="select" x-model="vatMode">
                        <option value="standard">{{ __('ƏDV-yə cəlb edilən') }}</option>
                        <option value="zero">{{ __('ƏDV 0 dərəcə') }}</option>
                        <option value="exempt">{{ __('ƏDV-dən azad') }}</option>
                        <option value="not_subject">{{ __('ƏDV-yə cəlb olunmayan') }}</option>
                    </select>
                </div>
                <div><label class="label" for="p-vat">{{ __('ƏDV dərəcəsi, %') }}</label><input id="p-vat" type="number" step="0.01" min="0" max="100" name="vat_rate" class="input" value="{{ $v['vat_rate'] }}" :readonly="vatMode !== 'standard'"></div>
                <div><label class="label" for="p-hs">{{ __('Malın kodu (XİF MN)') }}</label><input id="p-hs" name="hs_code" maxlength="20" class="input font-mono" value="{{ $v['hs_code'] }}" placeholder="2710198100"></div>
                <div><label class="label" for="p-excise">{{ __('Aksiz, %') }}</label><input id="p-excise" type="number" step="0.01" min="0" max="100" name="excise_rate" class="input" value="{{ $v['excise_rate'] }}"></div>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Stok həddləri və qiymət nəzarəti') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="p-min">{{ __('Minimum stok') }}</label><input id="p-min" type="number" step="any" min="0" name="min_stock_qty" class="input" value="{{ $v['min_stock_qty'] }}" placeholder="{{ __('boş = 14 günlük satış') }}"></div>
                <div><label class="label" for="p-max">{{ __('Maksimum stok') }}</label><input id="p-max" type="number" step="any" min="0" name="max_stock_qty" class="input" value="{{ $v['max_stock_qty'] }}"></div>
                <div><label class="label" for="p-minprice">{{ __('Minimum satış qiyməti') }}</label><input id="p-minprice" type="number" step="0.01" min="0" name="min_price" class="input" value="{{ $v['min_price'] }}" title="{{ __('Sifarişdə endirimdən sonra bu qiymətdən aşağı satılmır') }}"></div>
                <div><label class="label" for="p-maxdisc">{{ __('Maksimum endirim, %') }}</label><input id="p-maxdisc" type="number" step="0.01" min="0" max="100" name="max_discount_pct" class="input" value="{{ $v['max_discount_pct'] }}"></div>
            </div>
            @unless ($product)
                <div class="grid grid-cols-1 gap-3 border-t border-line p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><label class="label" for="p-sale">{{ __('Satış qiyməti') }}</label><input id="p-sale" type="number" step="0.01" min="0" name="sale_price" class="input" value="{{ old('sale_price') }}"></div>
                    <div><label class="label" for="p-purchase">{{ __('Alış qiyməti') }}</label><input id="p-purchase" type="number" step="0.01" min="0" name="purchase_price" class="input" value="{{ old('purchase_price') }}"></div>
                    <div class="flex items-end"><label class="flex h-9 items-center gap-2 text-sm"><input type="hidden" name="prices_vat_included" value="0"><input type="checkbox" name="prices_vat_included" value="1" class="size-4" @checked(old('prices_vat_included', true))> {{ __('Qiymətlər ƏDV daxil') }}</label></div>
                    <div class="flex items-end text-xs text-muted">{{ __('Bu gündən qüvvədə olan qiymət kartları yaradılacaq. Sonrakı dəyişikliklər — məhsul kartında «Qiymətlər».') }}</div>
                </div>
            @endunless
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Alternativ (əvəzedici) məhsullar') }}</h2></div>
            <div class="flex flex-col gap-2 p-4">
                <template x-for="id in alternatives" :key="id"><input type="hidden" name="alternatives[]" :value="id"></template>
                <div class="flex flex-wrap gap-1" x-show="alternatives.length">
                    <template x-for="(id, i) in alternatives" :key="id"><button type="button" class="badge badge-primary" @click="alternatives.splice(i, 1)"><span x-text="product(id)?.code + ' — ' + product(id)?.name"></span> ×</button></template>
                </div>
                <div class="relative max-w-xl" @click.outside="altOpen = false">
                    <input class="input" autocomplete="off" placeholder="{{ __('Məhsul əlavə et: kod və ya ad…') }}" aria-label="{{ __('Alternativ məhsul') }}" x-model="altQuery" @focus="altOpen = true" @input="altOpen = true">
                    <ul x-show="altOpen" x-cloak class="card absolute z-30 mt-1 max-h-64 w-full overflow-auto py-1 text-sm">
                        <template x-for="p in altMatches()" :key="p.id">
                            <li><button type="button" class="flex w-full gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="alternatives.push(p.id); altQuery = ''"><span class="font-mono text-xs text-muted" x-text="p.code"></span><span x-text="p.name"></span></button></li>
                        </template>
                    </ul>
                </div>
            </div>
        </section>

        <div class="flex justify-end gap-2">
            <a href="{{ $product ? route('products.show', $product) : route('products.index') }}" class="btn">{{ __('Ləğv et') }}</a>
            <button class="btn btn-primary">{{ $product ? __('Yadda saxla') : __('Məhsulu yarat') }}</button>
        </div>
    </form>
</x-layouts.app>
