<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use App\Models\Warehouse;
use App\Reports\Table;
use App\Services\PriceService;
use App\Services\ProductCatalogService;
use App\Services\SalesDocumentService;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Məhsul idarəetməsi: siyahı (stok göstəriciləri ilə), məhsul kartı, vahidlər, barkodlar, alternativlər.
 */
class ProductController extends ReportController
{
    public function index(Request $request, ProductCatalogService $catalog): View|Response
    {
        $warehouseId = is_numeric($request->query('warehouse')) ? (int) $request->query('warehouse') : null;
        $state = (string) $request->query('state', 'active');
        $rows = $catalog->rows(['warehouse_id' => $warehouseId]);
        $all = $rows;
        foreach (['brand' => 'brand_id', 'class' => 'product_class_id', 'category' => 'category_id', 'manufacturer' => 'manufacturer_id'] as $param => $field) {
            if (is_numeric($request->query($param))) {
                $rows = $rows->where($field, (int) $request->query($param));
            }
        }
        if (in_array($request->query('abc'), ['A', 'B', 'C'], true)) {
            $rows = $rows->where('abc', $request->query('abc'));
        }
        $rows = match ($state) {
            'all' => $rows,
            'active' => $rows->where('is_active', true),
            'reorder' => $rows->whereNotNull('reorder_qty'),
            default => $rows->where('state', $state),
        };
        $states = ProductCatalogService::stateLabels();

        $table = Table::make('products', __('Məhsullar'))
            ->column('code', __('Kod'), 'text', ['link' => fn ($r) => route('products.show', $r->id)])
            ->column('name', __('Ad'), 'text', ['format' => fn ($v, $r) => e($v)
                .($r->state !== 'ok' ? ' <span class="badge '.(in_array($r->state, ['negative', 'out'], true) ? 'badge-neg' : (in_array($r->state, ['low', 'over', 'dead'], true) ? 'badge-warn' : '')).'">'.e($states[$r->state]).'</span>' : '')
                .($r->custom_prices ? ' <span class="badge" title="'.e(__('Müştəriyə özəl qiymətlər')).'">★ '.$r->custom_prices.'</span>' : '')])
            ->column('barcodes', __('Barkod'), 'text', ['mobile' => false])
            ->column('brand_name', __('Marka'), 'text', ['mobile' => false])
            ->column('class_name', __('Sinif'), 'text', ['mobile' => false, 'format' => fn ($v, $r) => e($v ?? $r->category_name ?? '')])
            ->column('unit_name', __('Vahid'), 'text', ['mobile' => false])
            ->column('qty', __('Real stok'), 'qty', ['class' => fn ($v) => $v < 0 ? 'neg font-semibold' : 'font-semibold'])
            ->column('reserved', __('Rezerv'), 'qty', ['mobile' => false, 'class' => fn ($v) => $v > 0 ? 'warn' : 'text-muted'])
            ->column('available', __('Sərbəst'), 'qty', ['class' => fn ($v, $r) => $v < 0 ? 'neg' : ($r->state === 'low' ? 'warn font-semibold' : '')])
            ->column('min_effective', __('Min'), 'qty', ['mobile' => false])
            ->column('reorder_qty', __('Sifariş tövsiyəsi'), 'qty', ['mobile' => false, 'class' => fn ($v) => $v ? 'warn font-semibold' : ''])
            ->column('purchase_price', __('Alış qiyməti'), 'money', ['mobile' => false])
            ->column('sale_price', __('Satış qiyməti'), 'money')
            ->column('margin', __('Marja'), 'pct', ['mobile' => false, 'class' => fn ($v) => $v !== null && $v < 0 ? 'neg' : ''])
            ->column('stock_value', __('Stok dəyəri'), 'money', ['mobile' => false])
            ->column('sold30', __('30 gün satış'), 'qty', ['mobile' => false])
            ->column('turnover_days', __('Dövriyyə, gün'), 'int', ['mobile' => false, 'format' => fn ($v) => $v === null ? '—' : e(Fmt::number($v))])
            ->column('last_sale', __('Son satış'), 'date', ['mobile' => false])
            ->column('abc', 'ABC', 'text', ['mobile' => false, 'format' => fn ($v) => $v ? '<span class="badge '.($v === 'A' ? 'badge-primary' : '').'">'.$v.'</span>' : '—'])
            ->source($rows->values())->sortBy('name', 'asc')->perPage(100)->footer(auto: true)
            ->rowClass(fn ($r) => in_array($r->state, ['negative'], true) ? 'row-neg' : ($r->state === 'low' ? 'row-warn' : ''))
            ->subtitle(__('Sərbəst = real stok − sifarişlərdə rezerv. Min boşdursa 14 günlük satış götürülür. ABC — son :days günün gəliri üzrə.', ['days' => ProductCatalogService::ABC_DAYS]));

        if ($response = $this->exportIfRequested($request, [$table], __('Məhsullar'))) {
            return $response;
        }
        $active = $all->where('is_active', true);

        return view('products.index', [
            'title' => __('Məhsullar'), 'table' => $table, 'rows' => $table->resolve($request), 'state' => $state, 'states' => $states, 'warehouseId' => $warehouseId,
            'warehouses' => Warehouse::query()->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'filters' => $this->lookups(),
            'kpi' => [
                'count' => $active->count(), 'passive' => $all->where('is_active', false)->count(),
                'value' => $active->sum('stock_value'), 'low' => $all->where('state', 'low')->count(),
                'out' => $all->whereIn('state', ['out', 'negative'])->count(), 'reorder' => $all->whereNotNull('reorder_qty')->count(),
                'reserved' => $all->sum('reserved'), 'dead' => $all->where('state', 'dead')->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $copy = $request->query('copy') ? Product::query()->find($request->query('copy')) : null;

        return $this->form($copy, false);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = new Product(['source' => 'manual', 'stock_qty' => 0]);
        DB::transaction(fn () => $this->save($product, $request));

        return redirect()->route('products.show', $product)->with('popup', [
            'title' => __('Məhsul yaradıldı'), 'message' => null,
            'docs' => [['type' => __('Məhsul kartı'), 'no' => $product->code, 'url' => route('products.show', $product)]],
        ]);
    }

    public function show(Product $product, PriceService $prices, SalesDocumentService $documents): View
    {
        $product->load('brand', 'manufacturer', 'productClass', 'baseUnit', 'units.unit', 'category', 'group');
        $reserved = $documents->reserved();
        $stock = DB::table('stock_balances as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')->where('s.product_id', $product->id)
            ->orderByDesc('w.is_main')->get(['w.id', 'w.name', 's.qty', 's.last_movement_date'])
            ->map(fn ($r) => tap($r, function ($x) use ($reserved, $product) {
                $x->reserved = (float) ($reserved[$product->id][$x->id] ?? 0);
                $x->available = (float) $x->qty - $x->reserved;
            }));
        $cards = DB::table('price_cards as c')->leftJoin('price_lists as l', 'l.id', '=', 'c.price_list_id')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')->leftJoin('product_units as pu', 'pu.id', '=', 'c.product_unit_id')
            ->leftJoin('units as u', 'u.id', '=', 'pu.unit_id')->leftJoin('users as us', 'us.id', '=', 'c.created_by')
            ->where('c.product_id', $product->id)->orderByDesc('c.valid_from')->orderByDesc('c.id')
            ->get(['c.*', 'l.name as list_name', 'cu.name as customer_name', 'cu.code as customer_code', 'u.name as unit_name', 'pu.factor', 'us.name as user_name']);
        $today = now()->startOfDay();
        $sale = $prices->salePrice($product->id, null, $today);
        $purchase = $prices->purchasePrices($today, [$product->id])[$product->id] ?? null;
        $sales = DB::table('sales_lines')->where('product_id', $product->id)->where('op_type', 'sale')->where('date', '>=', $today->copy()->subDays(89)->toDateString())
            ->selectRaw('SUM(qty) AS qty, SUM(net_amount) AS amount, SUM(COALESCE(cogs, 0)) AS cogs')->first();

        return view('products.show', [
            'title' => $product->code.' — '.$product->name,
            'product' => $product, 'stock' => $stock, 'cards' => $cards, 'sale' => $sale, 'purchase' => $purchase, 'sales' => $sales,
            'barcodes' => DB::table('product_barcodes as b')->leftJoin('product_units as pu', 'pu.id', '=', 'b.product_unit_id')->leftJoin('units as u', 'u.id', '=', 'pu.unit_id')
                ->where('b.product_id', $product->id)->get(['b.barcode', 'u.name as unit_name', 'pu.factor']),
            'alternatives' => DB::table('product_alternatives as a')->join('products as p', 'p.id', '=', 'a.alternative_id')->where('a.product_id', $product->id)
                ->get(['p.id', 'p.code', 'p.name', 'p.stock_qty']),
            'priceLists' => DB::table('price_lists')->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'type', 'vat_included']),
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'code', 'name']),
            'today' => $today,
        ]);
    }

    public function edit(Product $product): View
    {
        return $this->form($product, true);
    }

    public function update(StoreProductRequest $request, Product $product): RedirectResponse
    {
        DB::transaction(fn () => $this->save($product, $request));

        return redirect()->route('products.show', $product)->with('status', __('Məhsul kartı yeniləndi.'));
    }

    public function toggle(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $product->forceFill(['is_active' => ! $product->is_active])->save();

        return back()->with('status', $product->is_active ? __('Məhsul aktivləşdirildi.') : __('Məhsul passiv edildi — yeni sifarişlərdə görünməyəcək.'));
    }

    /**
     * Barkod etiketləri (çap): ?ids[]=…&qty=…
     */
    public function labels(Request $request): View
    {
        $ids = array_map('intval', (array) $request->query('ids', []));
        $copies = max(1, min(200, (int) $request->query('qty', 1)));
        $withPrice = $request->boolean('price', true);
        $prices = app(PriceService::class)->salePrices(null, now()->startOfDay(), $ids ?: [0]);
        $products = DB::table('products')->whereIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'code', 'name', 'barcode']);

        return view('products.labels', ['products' => $products, 'copies' => $copies, 'prices' => $withPrice ? $prices : []]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lookups(): array
    {
        return [
            'brands' => DB::table('brands')->orderBy('name')->get(['id', 'name']),
            'manufacturers' => DB::table('manufacturers')->orderBy('name')->get(['id', 'name']),
            'classes' => DB::table('product_classes')->orderBy('name')->get(['id', 'name']),
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'name']),
            'groups' => DB::table('product_groups')->orderBy('name')->get(['id', 'name']),
            'units' => DB::table('units')->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function form(?Product $product, bool $editing): View
    {
        $p = $product;
        $units = $p ? DB::table('product_units')->where('product_id', $p->id)->get() : collect();
        $unitBarcodes = $p ? DB::table('product_barcodes')->where('product_id', $p->id)->whereNotNull('product_unit_id')->pluck('barcode', 'product_unit_id') : collect();
        $extra = $p ? DB::table('product_barcodes')->where('product_id', $p->id)->whereNull('product_unit_id')->pluck('barcode')->implode(' ') : '';

        return view('products.form', [
            'title' => $editing ? __('Məhsul kartı: düzəliş').' — '.$p->code : __('Yeni məhsul'),
            'product' => $editing ? $p : null,
            'values' => [
                'code' => old('code', $editing ? $p->code : ''),
                'name' => old('name', $p->name ?? ''),
                'short_name' => old('short_name', $p->short_name ?? ''),
                'barcode' => old('barcode', $editing ? ($p->barcode ?? '') : ''),
                'extra_barcodes' => old('extra_barcodes', $editing ? $extra : ''),
                'brand_id' => old('brand_id', $p->brand_id ?? DB::table('brands')->where('name', 'Valvoline')->value('id')),
                'manufacturer_id' => old('manufacturer_id', $p->manufacturer_id ?? null),
                'product_class_id' => old('product_class_id', $p->product_class_id ?? null),
                'category_id' => old('category_id', $p->category_id ?? null),
                'product_group_id' => old('product_group_id', $p->product_group_id ?? null),
                'base_unit_id' => old('base_unit_id', $p->base_unit_id ?? DB::table('units')->where('name', 'ədəd')->value('id')),
                'series' => old('series', $p->series ?? ''),
                'viscosity' => old('viscosity', $p->viscosity ?? ''),
                'specifications' => old('specifications', $p->specifications ?? ''),
                'application' => old('application', $p->application ?? ''),
                'volume_l' => old('volume_l', $p->volume_l ?? ''),
                'weight_kg' => old('weight_kg', $p->weight_kg ?? ''),
                'vat_mode' => old('vat_mode', $p->vat_mode ?? 'standard'),
                'vat_rate' => old('vat_rate', $p->vat_rate ?? 18),
                'excise_rate' => old('excise_rate', $p->excise_rate ?? ''),
                'hs_code' => old('hs_code', $p->hs_code ?? ''),
                'country_of_origin' => old('country_of_origin', $p->country_of_origin ?? ''),
                'shelf_life_months' => old('shelf_life_months', $p->shelf_life_months ?? ''),
                'min_stock_qty' => old('min_stock_qty', $p->min_stock_qty ?? ''),
                'max_stock_qty' => old('max_stock_qty', $p->max_stock_qty ?? ''),
                'min_price' => old('min_price', $p->min_price ?? ''),
                'max_discount_pct' => old('max_discount_pct', $p->max_discount_pct ?? ''),
                'description' => old('description', $p->description ?? ''),
                'is_active' => (bool) old('is_active', $p->is_active ?? true),
                'units' => array_values(old('units', $units->map(fn ($u) => ['unit_id' => $u->unit_id, 'factor' => (float) $u->factor, 'barcode' => $unitBarcodes[$u->id] ?? '', 'weight_kg' => $u->weight_kg])->all())),
                'sale_unit' => old('sale_unit', $units->firstWhere('is_sale_default', true)?->unit_id),
                'purchase_unit' => old('purchase_unit', $units->firstWhere('is_purchase_default', true)?->unit_id),
                'alternatives' => array_map('intval', old('alternatives', $p ? DB::table('product_alternatives')->where('product_id', $p->id)->pluck('alternative_id')->all() : [])),
            ],
            'lookups' => $this->lookups(),
            'allProducts' => DB::table('products')->when($p, fn ($q) => $q->where('id', '<>', $p->id))->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn ($x) => ['id' => $x->id, 'code' => $x->code, 'name' => $x->name])->values(),
            'priceLists' => DB::table('price_lists')->where('is_active', true)->get(['id', 'name', 'type', 'is_default']),
        ]);
    }

    private function save(Product $product, StoreProductRequest $request): void
    {
        $d = $request->validated();
        $isNew = ! $product->exists;
        $fields = ['code', 'name', 'short_name', 'barcode', 'brand_id', 'manufacturer_id', 'product_class_id', 'category_id', 'product_group_id', 'base_unit_id',
            'series', 'viscosity', 'specifications', 'application', 'volume_l', 'weight_kg', 'vat_mode', 'vat_rate', 'excise_rate', 'hs_code', 'country_of_origin',
            'shelf_life_months', 'min_stock_qty', 'max_stock_qty', 'min_price', 'max_discount_pct', 'description'];
        foreach ($fields as $f) {
            $product->{$f} = ($d[$f] ?? null) === '' ? null : ($d[$f] ?? null);
        }
        $product->volume_l ??= 0;
        $product->is_active = $request->boolean('is_active');
        if ($d['vat_mode'] !== 'standard') {
            $product->vat_rate = 0;
        }
        if ($request->hasFile('image')) {
            $dir = public_path('uploads/products');
            File::ensureDirectoryExists($dir);
            $name = $product->code ? preg_replace('/[^A-Za-z0-9_-]/', '_', $product->code).'-'.time().'.'.$request->file('image')->extension() : uniqid().'.jpg';
            $request->file('image')->move($dir, $name);
            $product->image_path = 'uploads/products/'.$name;
        } elseif ($request->boolean('remove_image')) {
            $product->image_path = null;
        }
        $product->save();

        // vahidlər və onların barkodları
        DB::table('product_units')->where('product_id', $product->id)->delete();
        DB::table('product_barcodes')->where('product_id', $product->id)->delete();
        $now = now();
        foreach ($d['units'] ?? [] as $u) {
            $unitId = DB::table('product_units')->insertGetId([
                'product_id' => $product->id, 'unit_id' => $u['unit_id'], 'factor' => $u['factor'], 'weight_kg' => $u['weight_kg'] ?? null,
                'is_sale_default' => (int) ($d['sale_unit'] ?? 0) === (int) $u['unit_id'], 'is_purchase_default' => (int) ($d['purchase_unit'] ?? 0) === (int) $u['unit_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if (! empty($u['barcode'])) {
                DB::table('product_barcodes')->insert(['product_id' => $product->id, 'product_unit_id' => $unitId, 'barcode' => $u['barcode'], 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (preg_split('/[\s,;]+/', (string) ($d['extra_barcodes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $code) {
            DB::table('product_barcodes')->insert(['product_id' => $product->id, 'product_unit_id' => null, 'barcode' => $code, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('product_alternatives')->where('product_id', $product->id)->delete();
        foreach (array_unique($d['alternatives'] ?? []) as $alt) {
            DB::table('product_alternatives')->insert(['product_id' => $product->id, 'alternative_id' => $alt, 'created_at' => $now, 'updated_at' => $now]);
        }

        // yeni məhsulda ilk qiymət kartları (bu gündən)
        if ($isNew) {
            $vatIncluded = $request->boolean('prices_vat_included', true);
            foreach (['sale' => $d['sale_price'] ?? null, 'purchase' => $d['purchase_price'] ?? null] as $type => $price) {
                if ($price !== null && $price !== '' && (float) $price > 0) {
                    DB::table('price_cards')->insert([
                        'product_id' => $product->id, 'price_list_id' => DB::table('price_lists')->where('type', $type)->where('is_default', true)->value('id'),
                        'type' => $type, 'price' => $price, 'currency' => 'AZN', 'vat_included' => $vatIncluded, 'valid_from' => $now->toDateString(),
                        'is_active' => true, 'created_by' => $request->user()->id, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
            if ((float) ($d['sale_price'] ?? 0) > 0) {
                $gross = $vatIncluded ? (float) $d['sale_price'] : (float) $d['sale_price'] * $product->vatFactor();
                $product->forceFill(['sale_price' => round($gross, 4)])->save();
            }
        }
    }
}
