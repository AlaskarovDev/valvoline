<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Manager;
use App\Services\CampaignEngine;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kampaniyalar: siyahı, yaratma/düzəliş, təsdiq axını (admin təsdiqləyir; adminin öz kampaniyası dərhal təsdiqlənir)
 * və sifariş formasında «Kampaniya ver» üçün hesablama.
 */
class CampaignController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q'));
        $user = $request->user();
        $campaigns = Campaign::query()->with('creator')
            ->when(! $user->isAdmin(), fn ($query) => $query->where(fn ($w) => $w->where('status', 'approved')->orWhere('created_by', $user->id)))
            ->when($status, fn ($query) => $query->whereIn('status', explode(',', $status)))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('end_date', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('begin_date', '<=', $to))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        $usage = $this->usage($campaigns->pluck('id')->all());
        $today = now()->startOfDay();
        $stats = [
            'running' => Campaign::query()->where('status', 'approved')->where('is_active', true)->whereDate('begin_date', '<=', $today)->whereDate('end_date', '>=', $today)->count(),
            'pending' => Campaign::query()->where('status', 'pending')->count(),
            'future' => Campaign::query()->where('status', 'approved')->whereDate('begin_date', '>', $today)->count(),
            'discount' => (float) DB::table('sales_item_campaigns')->sum('amount'),
        ];

        return view('campaigns.index', [
            'title' => __('Kampaniyalar'), 'campaigns' => $campaigns, 'usage' => $usage, 'stats' => $stats,
            'kinds' => Campaign::kindLabels(), 'statuses' => Campaign::statusLabels(), 'today' => $today,
        ]);
    }

    public function create(Request $request): View
    {
        $copy = $request->query('copy') ? Campaign::query()->find($request->query('copy')) : null;

        return $this->form($copy ? $copy->replicate(['code', 'status', 'approved_by', 'approved_at', 'rejection_reason']) : null, false);
    }

    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        $campaign = new Campaign(['created_by' => $request->user()->id]);
        $this->fillFrom($campaign, $request);
        // adminin kampaniyası dərhal təsdiqlənir, menecerinki admin təsdiqinə düşür
        $request->user()->isAdmin()
            ? $campaign->fill(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()])
            : $campaign->status = 'pending';
        $campaign->save();

        return redirect()->route('campaigns.show', $campaign)->with('status', $campaign->status === 'approved' ? __('Kampaniya yaradıldı və aktivdir.') : __('Kampaniya yaradıldı və təsdiqə göndərildi.'));
    }

    public function show(Campaign $campaign, Request $request): View
    {
        $this->authorizeView($request, $campaign);
        $orders = DB::table('sales_orders as o')->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.id', $this->orderIds($campaign->id))
            ->orderByDesc('o.date')->limit(500)
            ->get(['o.id', 'o.date', 'o.order_no', 'o.total_amount', DB::raw('COALESCE(c.name, o.customer_name) AS customer_name')]);
        $discounts = DB::table('sales_item_campaigns')->where('campaign_id', $campaign->id)->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, SUM(amount) AS amount')->pluck('amount', 'sales_order_id');
        $gifts = DB::table('sales_order_items')->where('campaign_id', $campaign->id)->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, SUM(qty) AS qty')->get()->keyBy('sales_order_id');

        return view('campaigns.show', [
            'title' => $campaign->code.' — '.$campaign->name,
            'campaign' => $campaign->load('creator', 'approver', 'giftProduct'),
            'orders' => $orders, 'discounts' => $discounts, 'gifts' => $gifts,
            'usage' => $this->usage([$campaign->id])[$campaign->id] ?? null,
            'targets' => $this->describeTargets($campaign),
            'kinds' => Campaign::kindLabels(), 'statuses' => Campaign::statusLabels(),
        ]);
    }

    public function edit(Request $request, Campaign $campaign): View
    {
        $this->authorizeEdit($request, $campaign);

        return $this->form($campaign, true);
    }

    public function update(StoreCampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        $this->authorizeEdit($request, $campaign);
        $this->fillFrom($campaign, $request);
        // menecer rədd edilmiş / ləğv edilmiş kampaniyanı düzəldib yenidən göndərir
        if (! $request->user()->isAdmin() && in_array($campaign->status, ['rejected', 'cancelled'], true)) {
            $campaign->fill(['status' => 'pending', 'rejection_reason' => null]);
        }
        $campaign->save();

        return redirect()->route('campaigns.show', $campaign)->with('status', __('Kampaniya yeniləndi.'));
    }

    public function decide(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'cancel', 'activate', 'deactivate'])], 'reason' => ['nullable', 'string', 'max:2000']]);
        $user = $request->user();
        $action = $data['action'];
        if (in_array($action, ['approve', 'reject', 'activate', 'deactivate'], true)) {
            abort_unless($user->isAdmin(), 403);
        } else {
            abort_unless($user->isAdmin() || ($campaign->created_by === $user->id && $campaign->status === 'pending'), 403);
        }
        if ($action === 'reject' && blank($data['reason'] ?? null)) {
            return back()->withErrors(['reason' => __('Rədd səbəbini yazın.')]);
        }
        match ($action) {
            'approve' => $campaign->fill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'rejection_reason' => null]),
            'reject' => $campaign->fill(['status' => 'rejected', 'rejection_reason' => $data['reason']]),
            'cancel' => $campaign->fill(['status' => 'cancelled']),
            'activate' => $campaign->fill(['is_active' => true]),
            'deactivate' => $campaign->fill(['is_active' => false]),
        };
        $campaign->save();

        return back()->with('status', [
            'approve' => __('Kampaniya təsdiqləndi.'), 'reject' => __('Kampaniya rədd edildi.'), 'cancel' => __('Kampaniya ləğv edildi.'),
            'activate' => __('Kampaniya aktivləşdirildi.'), 'deactivate' => __('Kampaniya dayandırıldı.'),
        ][$action]);
    }

    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($this->orderIds($campaign->id) !== []) {
            return back()->with('error', __('Kampaniya sifarişlərdə istifadə olunub — silmək olmaz, dayandırın.'));
        }
        $campaign->delete();

        return redirect()->route('campaigns.index')->with('status', __('Kampaniya silindi.'));
    }

    /**
     * Sifariş formasında «Kampaniya ver»: uyğun kampaniyaları hesablayıb sətir altı endirimləri və hədiyyələri qaytarır.
     */
    public function preview(Request $request, CampaignEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'date' => ['required', 'date'],
            'order_id' => ['nullable', 'integer'],
            'excluded' => ['nullable', 'array'], 'excluded.*' => ['integer'],
            'lines' => ['required', 'array', 'max:200'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.op_type' => ['nullable', 'string'],
            'lines.*.qty' => ['nullable', 'numeric'],
            'lines.*.price' => ['nullable', 'numeric'],
            'lines.*.discount' => ['nullable', 'numeric'],
        ]);
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $customer->manager_id !== $restricted, 403);
        $lines = array_map(fn ($l) => ['product_id' => $l['product_id'] ?? null, 'op_type' => $l['op_type'] ?? 'sale', 'qty' => $l['qty'] ?? 0, 'price' => $l['price'] ?? 0, 'discount' => $l['discount'] ?? 0], $data['lines']);

        return response()->json($engine->evaluate($customer, Carbon::parse($data['date']), $lines, array_map('intval', $data['excluded'] ?? []), $data['order_id'] ?? null));
    }

    private function form(?Campaign $campaign, bool $editing): View
    {
        $products = DB::table('products')->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name])->values();
        $customers = DB::table('customers')->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values();
        $c = $campaign;
        $productScope = old('product_scope', $c->product_scope ?? 'all');
        $filter = $c->product_filter ?? [];

        return view('campaigns.form', [
            'title' => $editing ? __('Kampaniya: düzəliş').' — '.$c->code : __('Yeni kampaniya'),
            'campaign' => $editing ? $c : null,
            'values' => [
                'code' => old('code', $editing ? $c->code : ''),
                'name' => old('name', $c->name ?? ''),
                'description' => old('description', $c->description ?? ''),
                'kind' => old('kind', $c->kind ?? 'simple_discount'),
                'calc_basis' => old('calc_basis', $c->calc_basis ?? 'qty'),
                'formula_base' => old('formula_base', $c->formula_base ?? 'P3'),
                'discount_rate' => old('discount_rate', $c->discount_rate ?? ''),
                'threshold_value' => old('threshold_value', $c->threshold_value ?? ''),
                'tier_calc' => old('tier_calc', $c->tier_calc ?? 'percent'),
                'tier_scope' => old('tier_scope', $c->tier_scope ?? 'products'),
                'tiers' => array_values(old('tiers', $c->tiers ?? [])),
                'gift_mode' => old('gift_mode', $c->gift_mode ?? 'proportional'),
                'gift_product_id' => (int) old('gift_product_id', $c->gift_product_id ?? 0) ?: null,
                'gift_qty' => old('gift_qty', $c->gift_qty ?? ''),
                'gift_divisor' => old('gift_divisor', $c->gift_divisor ?? ''),
                'vat_multiplier' => old('vat_multiplier', $c->vat_multiplier ?? 1),
                'product_scope' => $productScope,
                'products' => array_values(old('products', $productScope === 'products' ? $filter : [])),
                'groups' => array_map('intval', old('groups', $productScope === 'groups' ? $filter : [])),
                'categories' => array_map('intval', old('categories', $productScope === 'categories' ? $filter : [])),
                'series' => old('series', $productScope === 'series' ? $filter : []),
                'excluded_products' => array_map('intval', old('excluded_products', $c->excluded_products ?? [])),
                'customer_scope' => old('customer_scope', $c->customer_scope ?? 'all'),
                'customers' => array_map('intval', old('customers', ($c->customer_scope ?? '') === 'customers' ? ($c->customer_filter ?? []) : [])),
                'managers' => array_map('intval', old('managers', ($c->customer_scope ?? '') === 'managers' ? ($c->customer_filter ?? []) : [])),
                'customer_excludes' => array_map('intval', old('customer_excludes', $c->customer_excludes ?? [])),
                'begin_date' => old('begin_date', $c?->begin_date?->toDateString() ?? now()->toDateString()),
                'end_date' => old('end_date', $c?->end_date?->toDateString() ?? now()->endOfMonth()->toDateString()),
                'priority' => old('priority', $c->priority ?? 0),
                'priority_group' => old('priority_group', $c->priority_group ?? ''),
                'apply_limit' => old('apply_limit', $c->apply_limit ?? 0),
                'estimated_budget' => old('estimated_budget', $c->estimated_budget ?? ''),
                'is_active' => (bool) old('is_active', $c->is_active ?? true),
            ],
            'products' => $products,
            'customers' => $customers,
            'groups' => DB::table('product_groups')->orderBy('name')->get(['id', 'name']),
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'name']),
            'seriesList' => DB::table('products')->whereNotNull('series')->where('series', '<>', '')->distinct()->orderBy('series')->pluck('series'),
            'managers' => Manager::query()->orderBy('name')->get(['id', 'name']),
            'kinds' => Campaign::kindLabels(),
        ]);
    }

    private function fillFrom(Campaign $campaign, StoreCampaignRequest $request): void
    {
        $d = $request->validated();
        $kind = $d['kind'];
        $isGift = in_array($kind, ['qty_gift', 'amount_gift'], true);
        $tiered = $kind === 'tiered_discount' || ($isGift && in_array($d['gift_mode'] ?? null, ['tiered', 'tiered_multiple'], true));
        $productScope = $d['product_scope'];
        $productFilter = match ($productScope) {
            'products' => array_values(array_map(fn ($p) => [
                'id' => (int) $p['id'],
                'rate' => isset($p['rate']) && $p['rate'] !== '' ? (float) $p['rate'] : null,
                'price' => isset($p['price']) && $p['price'] !== '' ? (float) $p['price'] : null,
            ], $d['products'] ?? [])),
            'groups' => array_map('intval', $d['groups'] ?? []),
            'categories' => array_map('intval', $d['categories'] ?? []),
            'series' => array_values($d['series'] ?? []),
            default => null,
        };
        $calcBasis = match ($kind) {
            'amount_threshold_discount', 'amount_gift', 'invoice_bottom_discount' => 'amount',
            'qty_threshold_discount', 'qty_gift', 'simple_discount', 'max_discount_cap', 'target_price' => 'qty',
            default => $d['calc_basis'] ?? 'qty',
        };

        $campaign->fill([
            'code' => $d['code'] ?? null ?: ($campaign->code ?? $this->nextCode(Carbon::parse($d['begin_date']))),
            'name' => $d['name'],
            'description' => $d['description'] ?? null,
            'kind' => $kind,
            'calc_basis' => $calcBasis,
            'formula_base' => $d['formula_base'],
            'discount_rate' => in_array($kind, ['simple_discount', 'qty_threshold_discount', 'amount_threshold_discount', 'invoice_bottom_discount', 'max_discount_cap'], true) ? ($d['discount_rate'] ?? null) : null,
            'threshold_value' => in_array($kind, ['qty_threshold_discount', 'amount_threshold_discount'], true) || ($isGift && ! $tiered) ? ($d['threshold_value'] ?? null) : null,
            'tiers' => $tiered ? array_values(array_map(fn ($t) => ['from' => (float) $t['from'], 'to' => isset($t['to']) && $t['to'] !== '' ? (float) $t['to'] : null, 'value' => (float) $t['value']], $d['tiers'] ?? [])) : null,
            'tier_calc' => $kind === 'tiered_discount' ? ($d['tier_calc'] ?? 'percent') : 'percent',
            'tier_scope' => $kind === 'tiered_discount' ? ($d['tier_scope'] ?? 'products') : 'products',
            'gift_mode' => $isGift && ($d['gift_mode'] ?? 'proportional') !== 'proportional' ? $d['gift_mode'] : null,
            'gift_product_id' => $isGift ? ($d['gift_product_id'] ?? null) : null,
            'gift_qty' => $isGift && ! $tiered ? ($d['gift_qty'] ?? null) : null,
            'gift_divisor' => $isGift && ! $tiered ? ($d['gift_divisor'] ?? null) : null,
            'vat_multiplier' => $d['vat_multiplier'],
            'product_scope' => $productScope,
            'product_filter' => $productFilter,
            'excluded_products' => array_values(array_map('intval', $d['excluded_products'] ?? [])) ?: null,
            'customer_scope' => $d['customer_scope'],
            'customer_filter' => match ($d['customer_scope']) {
                'customers' => array_values(array_map('intval', $d['customers'] ?? [])),
                'managers' => array_values(array_map('intval', $d['managers'] ?? [])),
                default => null,
            },
            'customer_excludes' => array_values(array_map('intval', $d['customer_excludes'] ?? [])) ?: null,
            'begin_date' => $d['begin_date'],
            'end_date' => $d['end_date'],
            'priority' => (int) ($d['priority'] ?? 0),
            'priority_group' => $d['priority_group'] ?? null ?: null,
            'apply_limit' => (int) ($d['apply_limit'] ?? 0),
            'estimated_budget' => $d['estimated_budget'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);
    }

    /**
     * Avtomatik kod: KMP.YYYYMM.NNNN.
     */
    private function nextCode(Carbon $date): string
    {
        $prefix = 'KMP.'.$date->format('Ym').'.';
        $last = Campaign::query()->where('code', 'like', $prefix.'%')->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<int>
     */
    private function orderIds(int $campaignId): array
    {
        return DB::table('sales_item_campaigns')->where('campaign_id', $campaignId)->pluck('sales_order_id')
            ->merge(DB::table('sales_order_items')->where('campaign_id', $campaignId)->pluck('sales_order_id'))
            ->filter()->unique()->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{orders: int, discount: float, gift_qty: float}>
     */
    private function usage(array $ids): array
    {
        $out = [];
        foreach (DB::table('sales_item_campaigns')->whereIn('campaign_id', $ids ?: [0])->groupBy('campaign_id')
            ->selectRaw('campaign_id, COUNT(DISTINCT sales_order_id) AS orders, SUM(amount) AS amount')->get() as $r) {
            $out[$r->campaign_id] = ['orders' => (int) $r->orders, 'discount' => (float) $r->amount, 'gift_qty' => 0.0];
        }
        foreach (DB::table('sales_order_items')->whereIn('campaign_id', $ids ?: [0])->groupBy('campaign_id')
            ->selectRaw('campaign_id, COUNT(DISTINCT sales_order_id) AS orders, SUM(qty) AS qty')->get() as $r) {
            $out[$r->campaign_id] ??= ['orders' => 0, 'discount' => 0.0, 'gift_qty' => 0.0];
            $out[$r->campaign_id]['orders'] = max($out[$r->campaign_id]['orders'], (int) $r->orders);
            $out[$r->campaign_id]['gift_qty'] = (float) $r->qty;
        }

        return $out;
    }

    /**
     * @return array{products: string, customers: string, excluded: string|null, customer_excludes: string|null, condition: string}
     */
    private function describeTargets(Campaign $c): array
    {
        $names = fn (string $table, array $ids, string $col = 'name') => DB::table($table)->whereIn('id', array_map('intval', $ids) ?: [0])->orderBy($col)->pluck($col)->implode(', ');
        $filter = $c->product_filter ?? [];
        $products = match ($c->product_scope) {
            'products' => DB::table('products')->whereIn('id', array_column($filter, 'id') ?: [0])->get(['id', 'code', 'name'])->keyBy('id')
                ->pipe(fn ($p) => collect($filter)->map(function ($row) use ($p) {
                    $product = $p[$row['id']] ?? null;
                    $extra = array_filter([$row['rate'] !== null ? Fmt::number($row['rate'], 2).' %' : null, $row['price'] !== null ? Fmt::money($row['price']) : null]);

                    return $product ? $product->code.' — '.$product->name.($extra ? ' ('.implode(', ', $extra).')' : '') : null;
                })->filter()->implode('; ')),
            'groups' => __('Qruplar').': '.$names('product_groups', $filter),
            'categories' => __('Kateqoriyalar').': '.$names('categories', $filter),
            'series' => __('Seriyalar').': '.implode(', ', $filter),
            default => __('Bütün mallar'),
        };
        $customers = match ($c->customer_scope) {
            'customers' => $names('customers', $c->customer_filter ?? []),
            'managers' => __('Satış nümayəndələrinin müştəriləri').': '.$names('managers', $c->customer_filter ?? []),
            default => __('Bütün müştərilər'),
        };
        $unit = $c->calc_basis === 'amount' ? ' ₼' : ' '.__('əd.');
        $tiers = collect($c->tiers ?? [])->map(fn ($t) => Fmt::number($t['from'], 2).' – '.($t['to'] !== null ? Fmt::number($t['to'], 2) : '∞').$unit.' → '.Fmt::number($t['value'], 2).($c->isGift() ? ' '.__('əd.') : ' %'))->implode('; ');
        $condition = match ($c->kind) {
            'simple_discount', 'invoice_bottom_discount' => Fmt::number((float) $c->discount_rate, 2).' %',
            'qty_threshold_discount', 'amount_threshold_discount' => '≥ '.Fmt::number((float) $c->threshold_value, 2).$unit.' → '.Fmt::number((float) $c->discount_rate, 2).' %',
            'tiered_discount' => $tiers.($c->tier_calc === 'cap' ? ' · '.__('tavan rejimi') : '').($c->tier_scope === 'document' ? ' · '.__('bütün sənəd üzrə') : ''),
            'max_discount_cap' => __('ümumi endirim ən çox').' '.Fmt::number((float) $c->discount_rate, 2).' %',
            'target_price' => __('mal üzrə hədəf qiymət'),
            default => $c->gift_mode ? $tiers.($c->gift_mode === 'tiered_multiple' ? ' · '.__('çoxqat') : '')
                : __('hər :n:unit üçün :m əd. hədiyyə', ['n' => Fmt::number((float) $c->gift_divisor, 2), 'unit' => $unit, 'm' => Fmt::number((float) $c->gift_qty, 2)]),
        };

        return [
            'products' => $products, 'customers' => $customers, 'condition' => $condition,
            'excluded' => $c->excluded_products ? DB::table('products')->whereIn('id', $c->excluded_products)->pluck('code')->implode(', ') : null,
            'customer_excludes' => $c->customer_excludes ? $names('customers', $c->customer_excludes) : null,
        ];
    }

    private function authorizeView(Request $request, Campaign $campaign): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $campaign->status === 'approved' || $campaign->created_by === $user->id, 403);
    }

    private function authorizeEdit(Request $request, Campaign $campaign): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || ($campaign->created_by === $user->id && $campaign->status !== 'approved'), 403);
    }
}
