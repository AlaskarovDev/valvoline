<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Models\Customer;
use App\Models\PriceCard;
use App\Reports\Table;
use App\Services\PriceService;
use App\Support\Fmt;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Qiymət kartları: tarixli satış/alış qiymətləri, müştəriyə özəl qiymət, toplu qiymət dəyişikliyi,
 * müştəri / qiymət növü üzrə qiymət siyahısı və Excel-dən qiymət yükləmə.
 */
class PriceCardController extends ReportController
{
    /**
     * Müştəriyə özəl qiymət kartları (ayrıca alt menyu).
     */
    public function customerIndex(Request $request): View|Response
    {
        return $this->index($request, true);
    }

    public function index(Request $request, bool $customerOnly = false): View|Response
    {
        $state = (string) $request->query('state', 'current');
        $today = now()->toDateString();
        $query = DB::table('price_cards as c')->join('products as p', 'p.id', '=', 'c.product_id')
            ->leftJoin('price_lists as l', 'l.id', '=', 'c.price_list_id')->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->leftJoin('product_units as pu', 'pu.id', '=', 'c.product_unit_id')->leftJoin('units as u', 'u.id', '=', 'pu.unit_id')
            ->when(in_array($request->query('type'), ['sale', 'purchase'], true), fn ($q) => $q->where('c.type', $request->query('type')))
            ->when(is_numeric($request->query('list')), fn ($q) => $q->where('c.price_list_id', (int) $request->query('list')))
            // əsas siyahıda yalnız qiymət növü kartları, müştəriyə özəl kartlar ayrıca səhifədədir
            ->when($customerOnly, fn ($q) => $q->whereNotNull('c.customer_id'), fn ($q) => $q->whereNull('c.customer_id'))
            ->when(is_numeric($request->query('customer')), fn ($q) => $q->where('c.customer_id', (int) $request->query('customer')))
            ->when($state === 'current', fn ($q) => $q->where('c.is_active', true)->whereDate('c.valid_from', '<=', $today)->where(fn ($w) => $w->whereNull('c.valid_to')->orWhereDate('c.valid_to', '>=', $today)))
            ->when($state === 'future', fn ($q) => $q->where('c.is_active', true)->whereDate('c.valid_from', '>', $today))
            ->when($state === 'expired', fn ($q) => $q->where(fn ($w) => $w->where('c.is_active', false)->orWhereDate('c.valid_to', '<', $today)))
            ->select('c.id', 'c.product_id', 'p.code', 'p.name', 'c.type', 'c.price', 'c.vat_included', 'c.min_qty', 'c.valid_from', 'c.valid_to', 'c.is_active', 'c.note',
                'l.name as list_name', 'cu.name as customer_name', 'c.customer_id', 'u.name as unit_name', 'p.vat_rate', 'p.vat_mode');

        $table = Table::make('cards', $customerOnly ? __('Müştəriyə özəl qiymətlər') : __('Qiymət kartları'))
            ->column('code', __('Kod'), 'text', ['sql' => 'p.code', 'link' => fn ($r) => route('products.show', $r->product_id)])
            ->column('name', __('Mal'), 'text', ['sql' => 'p.name'])
            ->column('type', __('Növ'), 'text', ['sql' => 'c.type', 'format' => fn ($v) => $v === 'sale' ? e(__('Satış')) : '<span class="badge">'.e(__('Alış')).'</span>'])
            ->column('list_name', __('Qiymət növü'), 'text', ['sql' => 'l.name', 'format' => fn ($v, $r) => $r->customer_id ? '<span class="badge badge-primary">★ '.e($r->customer_name).'</span>' : e($v ?? '—')])
            ->column('unit_name', __('Vahid'), 'text', ['sql' => 'u.name', 'mobile' => false, 'format' => fn ($v) => e($v ?? __('əsas'))])
            ->column('price', __('Qiymət'), 'money', ['sql' => 'c.price'])
            ->column('vat_included', __('ƏDV'), 'text', ['sql' => 'c.vat_included', 'format' => fn ($v) => $v ? e(__('daxil')) : e(__('xaric'))])
            ->column('gross', __('ƏDV daxil'), 'money', ['sortable' => false, 'mobile' => false, 'format' => fn ($v, $r) => e(Fmt::money($r->vat_included ? $r->price : $r->price * ($r->vat_mode === 'standard' ? 1 + $r->vat_rate / 100 : 1)))])
            ->column('min_qty', __('Min. miqdar'), 'qty', ['sql' => 'c.min_qty', 'mobile' => false])
            ->column('valid_from', __('Başlama'), 'date', ['sql' => 'c.valid_from'])
            ->column('valid_to', __('Bitmə'), 'date', ['sql' => 'c.valid_to'])
            ->column('note', __('Qeyd'), 'text', ['sql' => 'c.note', 'mobile' => false])
            ->source($query)->sortBy('valid_from', 'desc')->perPage(100);

        if ($response = $this->exportIfRequested($request, [$table], __('Qiymət kartları'))) {
            return $response;
        }

        return view('prices.index', [
            'title' => $customerOnly ? __('Müştəriyə özəl qiymətlər') : __('Qiymət kartları'), 'customerOnly' => $customerOnly,
            'products' => $customerOnly ? DB::table('products')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'table' => $table, 'rows' => $table->resolve($request), 'state' => $state,
            'priceLists' => DB::table('price_lists')->orderByDesc('is_default')->orderBy('name')->get(),
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'name', 'code']),
            'brands' => DB::table('brands')->orderBy('name')->get(['id', 'name']),
            'classes' => DB::table('product_classes')->orderBy('name')->get(['id', 'name']),
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $card = $this->createCard($data, $request->user()->id, $request->boolean('close_previous', true));

        return back()->with('status', __('Qiymət kartı əlavə olundu: :price, :date tarixindən.', ['price' => Fmt::money($card->price), 'date' => Fmt::date($card->valid_from)]));
    }

    public function update(Request $request, PriceCard $card): RedirectResponse
    {
        abort_unless($request->user()->can('products.manage'), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['close', 'activate', 'deactivate'])],
            'valid_to' => ['nullable', 'date'],
        ]);
        match ($data['action']) {
            'close' => $card->fill(['valid_to' => $data['valid_to'] ?? now()->subDay()->toDateString()]),
            'activate' => $card->fill(['is_active' => true]),
            'deactivate' => $card->fill(['is_active' => false]),
        };
        $card->save();

        return back()->with('status', __('Qiymət kartı yeniləndi.'));
    }

    public function destroy(Request $request, PriceCard $card): RedirectResponse
    {
        abort_unless($request->user()->can('products.manage'), 403);
        $card->delete();

        return back()->with('status', __('Qiymət kartı silindi.'));
    }

    /**
     * Toplu qiymət dəyişikliyi: seçilmiş mallar üçün yeni kartlar (cari qiymət ± %, alış qiymətinə əlavə və ya sabit qiymət).
     */
    public function bulk(Request $request, PriceService $prices): RedirectResponse
    {
        abort_unless($request->user()->can('products.manage'), 403);
        $data = $request->validate([
            'price_list_id' => ['required', 'integer', 'exists:price_lists,id'],
            'method' => ['required', Rule::in(['percent', 'markup', 'fixed'])],
            'value' => ['required', 'numeric', 'min:-99', 'max:100000'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'round' => ['nullable', Rule::in(['0.01', '0.05', '0.1', '1'])],
            'brand' => ['nullable', 'integer'], 'class' => ['nullable', 'integer'], 'category' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $list = DB::table('price_lists')->find($data['price_list_id']);
        $products = DB::table('products')->where('is_active', true)
            ->when($data['brand'] ?? null, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($data['class'] ?? null, fn ($q, $v) => $q->where('product_class_id', $v))
            ->when($data['category'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('code', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%")))
            ->get(['id', 'vat_rate', 'vat_mode', 'sale_price']);
        $date = Carbon::parse($data['valid_from']);
        $current = $list->type === 'sale' ? $prices->salePrices(null, $date, $products->pluck('id')->all()) : [];
        $purchase = $prices->purchasePrices($date, $products->pluck('id')->all());
        $step = (float) ($data['round'] ?? 0.01);
        $count = 0;
        foreach ($products as $p) {
            $factor = $p->vat_mode === 'standard' ? 1 + (float) $p->vat_rate / 100 : 1.0;
            // hesablama həmişə ƏDV daxil qiymətlə, sonra qiymət növünün ƏDV rejiminə çevrilir
            $gross = match ($data['method']) {
                'percent' => isset($current[$p->id]) ? $current[$p->id]['price'] * (1 + $data['value'] / 100) : null,
                'markup' => isset($purchase[$p->id]) ? $purchase[$p->id]['price'] * (1 + $data['value'] / 100) : null,
                'fixed' => $list->vat_included ? (float) $data['value'] : (float) $data['value'] * $factor,
            };
            if ($gross === null || $gross <= 0) {
                continue;
            }
            $gross = round(round($gross / $step) * $step, 4);
            $this->createCard([
                'product_id' => $p->id, 'price_list_id' => $list->id, 'type' => $list->type, 'customer_id' => null, 'product_unit_id' => null,
                'price' => $list->vat_included ? $gross : round($gross / $factor, 4), 'vat_included' => (bool) $list->vat_included, 'min_qty' => 0,
                'valid_from' => $data['valid_from'], 'valid_to' => $data['valid_to'] ?? null,
                'note' => ['percent' => __('Toplu dəyişiklik :v %', ['v' => $data['value']]), 'markup' => __('Alış qiymətinə :v % əlavə', ['v' => $data['value']]), 'fixed' => __('Toplu sabit qiymət')][$data['method']],
            ], $request->user()->id, true);
            $count++;
        }

        return back()->with('popup', ['title' => __('Qiymətlər yeniləndi'), 'message' => __(':n mal üçün «:list» qiyməti :date tarixindən qüvvəyə minəcək.', ['n' => $count, 'list' => $list->name, 'date' => Fmt::date($date)]), 'docs' => []]);
    }

    /**
     * Excel-dən qiymət yükləmə: Kod | Qiymət [| Başlama tarixi].
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('products.manage'), 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'price_list_id' => ['required', 'integer', 'exists:price_lists,id'],
            'valid_from' => ['required', 'date'],
        ]);
        $list = DB::table('price_lists')->find($data['price_list_id']);
        $products = DB::table('products')->pluck('id', 'code');
        $path = tempnam(sys_get_temp_dir(), 'prc').'.'.strtolower($request->file('file')->getClientOriginalExtension());
        copy($request->file('file')->getRealPath(), $path);
        $count = 0;
        $missing = [];
        $sheet = array_key_first(Spreadsheet::inspect($path));
        foreach ($sheet === null ? [] : Spreadsheet::rows($path, $sheet) as $row) {
            $values = array_values($row);
            $code = Spreadsheet::code($values[0] ?? null);
            $price = Spreadsheet::number($values[1] ?? null);
            if ($code === null || $price === null || $price <= 0) {
                continue;
            }
            if (! isset($products[$code])) {
                $missing[] = $code;

                continue;
            }
            $from = ($d = Spreadsheet::date($values[2] ?? null)) ? $d->toDateString() : $data['valid_from'];
            $this->createCard([
                'product_id' => $products[$code], 'price_list_id' => $list->id, 'type' => $list->type, 'customer_id' => null, 'product_unit_id' => null,
                'price' => $price, 'vat_included' => (bool) $list->vat_included, 'min_qty' => 0, 'valid_from' => $from, 'valid_to' => null, 'note' => __('Excel-dən'),
            ], $request->user()->id, true);
            $count++;
        }

        @unlink($path);

        return back()->with('popup', [
            'title' => __('Qiymətlər yükləndi'),
            'message' => __(':n qiymət kartı yaradıldı.', ['n' => $count]).($missing ? ' '.__('Tapılmayan kodlar: :codes', ['codes' => implode(', ', array_slice(array_unique($missing), 0, 20))]) : ''),
            'docs' => [],
        ]);
    }

    /**
     * Qiymət siyahısı (prays-list): müştəri və ya qiymət növü üzrə, tarixə görə — ekranda, Excel-də və çapda.
     */
    public function priceList(Request $request, PriceService $prices): View|Response
    {
        $date = Fmt::carbonOrNull((string) $request->query('date')) ?? now()->startOfDay();
        $customer = is_numeric($request->query('customer')) ? Customer::query()->find((int) $request->query('customer')) : null;
        $listId = is_numeric($request->query('list')) ? (int) $request->query('list') : null;
        if ($listId && ! $customer) {
            // qiymət növü seçilibsə həmin növə bağlı «virtual» müştəri kimi hesablanır
            $customer = new Customer(['price_list_id' => $listId]);
            $customer->id = 0;
        }
        $result = $prices->salePrices($customer, $date);
        $rows = DB::table('products as p')->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->where('p.is_active', true)->orderBy('p.name')
            ->when(is_numeric($request->query('brand')), fn ($q) => $q->where('p.brand_id', (int) $request->query('brand')))
            ->get(['p.id', 'p.code', 'p.name', 'p.barcode', 'p.volume_l', 'p.vat_rate', 'p.vat_mode', 'b.name as brand_name', 'u.name as unit_name', 'p.stock_qty'])
            ->filter(fn ($p) => isset($result[$p->id]))
            ->map(fn ($p) => tap($p, function ($x) use ($result) {
                $r = $result[$x->id];
                $x->price = $r['price'];
                $x->net = $r['net'];
                $x->source = $r['label'];
                $x->special = $r['source'] === 'customer';
            }))->values();

        $table = Table::make('pricelist', __('Qiymət siyahısı'))
            ->column('code', __('Kod'))
            ->column('name', __('Mal'), 'text', ['format' => fn ($v, $r) => e($v).($r->special ? ' <span class="badge badge-primary">★ '.e(__('özəl')).'</span>' : '')])
            ->column('barcode', __('Barkod'), 'text', ['mobile' => false])
            ->column('brand_name', __('Marka'), 'text', ['mobile' => false])
            ->column('unit_name', __('Vahid'))
            ->column('net', __('ƏDV-siz'), 'money')
            ->column('price', __('ƏDV daxil'), 'money', ['class' => fn () => 'font-semibold'])
            ->column('source', __('Mənbə'), 'text', ['mobile' => false])
            ->source($rows)->sortBy('name', 'asc')->perPage(500);

        $title = __('Qiymət siyahısı').($customer && $customer->exists ? ' — '.$customer->name : ($listId ? ' — '.DB::table('price_lists')->where('id', $listId)->value('name') : '')).' · '.$date->format('d.m.Y');
        if ($response = $this->exportIfRequested($request, [$table], $title)) {
            return $response;
        }

        return view('prices.pricelist', [
            'title' => __('Qiymət siyahısı'), 'heading' => $title, 'table' => $table, 'rows' => $table->resolve($request), 'date' => $date,
            'customer' => $customer?->exists ? $customer : null, 'listId' => $listId,
            'priceLists' => DB::table('price_lists')->where('type', 'sale')->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'name', 'code']),
            'brands' => DB::table('brands')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Sifariş forması üçün: seçilmiş müştəriyə və tarixə görə qiymətlər (JSON).
     */
    public function forCustomer(Request $request, PriceService $prices): JsonResponse
    {
        $data = $request->validate(['customer_id' => ['required', 'integer', 'exists:customers,id'], 'date' => ['required', 'date']]);
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $customer->manager_id !== $restricted, 403);

        return response()->json(collect($prices->salePrices($customer, Carbon::parse($data['date'])))
            ->map(fn ($p) => ['price' => round($p['price'], 2), 'label' => $p['label'], 'special' => $p['source'] === 'customer']));
    }

    /**
     * Müştəriyə qiymət növü təyin etmək.
     */
    public function assignList(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()->can('products.manage'), 403);
        $data = $request->validate(['price_list_id' => ['nullable', 'integer', 'exists:price_lists,id']]);
        $customer->forceFill(['price_list_id' => $data['price_list_id'] ?? null])->save();

        return back()->with('status', __('Müştərinin qiymət növü yeniləndi.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        abort_unless($request->user()->can('products.manage'), 403);

        return $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type' => ['required', Rule::in(['sale', 'purchase'])],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'price' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'vat_included' => ['required', 'boolean'],
            'min_qty' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * Yeni kart; eyni açarlı (mal + növ + qiymət növü + müştəri + vahid + min miqdar) açıq kart varsa, yeni kartın başlama tarixindən bir gün əvvəl bağlanır.
     *
     * @param  array<string, mixed>  $data
     */
    private function createCard(array $data, ?int $userId, bool $closePrevious): PriceCard
    {
        $from = Carbon::parse($data['valid_from']);
        if ($closePrevious) {
            PriceCard::query()->where('product_id', $data['product_id'])->where('type', $data['type'])
                ->where('price_list_id', $data['price_list_id'] ?? null)->where('customer_id', $data['customer_id'] ?? null)
                ->where('product_unit_id', $data['product_unit_id'] ?? null)->where('min_qty', (float) ($data['min_qty'] ?? 0))
                ->whereDate('valid_from', '<', $from->toDateString())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from->toDateString()))
                ->update(['valid_to' => $from->copy()->subDay()->toDateString(), 'updated_at' => now()]);
        }

        return PriceCard::query()->create([
            'product_id' => $data['product_id'], 'price_list_id' => $data['price_list_id'] ?? null, 'type' => $data['type'],
            'customer_id' => $data['customer_id'] ?? null, 'product_unit_id' => $data['product_unit_id'] ?? null,
            'price' => $data['price'], 'currency' => 'AZN', 'vat_included' => (bool) $data['vat_included'], 'min_qty' => (float) ($data['min_qty'] ?? 0),
            'valid_from' => $from->toDateString(), 'valid_to' => $data['valid_to'] ?? null, 'is_active' => true, 'note' => $data['note'] ?? null,
            'created_by' => $userId,
        ]);
    }
}
