<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Jobs\RebuildAnalytics;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Manager;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\Workplace;
use App\Services\AnalyticsRebuilder;
use App\Services\CampaignEngine;
use App\Services\PriceService;
use App\Services\SalesDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Sistemdə sifariş: yaratmaq / düzəltmək / göndərmək (təhvil-təslim) / fakturalamaq.
 * Sifariş yalnız rezerv edir; real stok TT-dən, müştəri borcu fakturadan dəyişir.
 */
class OrderEntryController extends Controller
{
    public function __construct(private CampaignEngine $campaigns, private SalesDocumentService $documents) {}

    public function create(Request $request): View
    {
        return $this->form($request, null);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $order = DB::transaction(fn () => $this->save($request, new SalesOrder(['source' => 'manual', 'status' => 'open', 'created_by' => $request->user()->id])));
        $created = [[__('Sifariş'), $order->order_no, route('orders.show', $order)]];

        return $this->afterSave($request, $order, $created);
    }

    public function edit(Request $request, SalesOrder $order): View
    {
        $this->authorizeManual($request, $order, true);

        return $this->form($request, $order);
    }

    public function update(StoreOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        $this->authorizeManual($request, $order, true);
        DB::transaction(fn () => $this->save($request, $order));

        return $this->afterSave($request, $order, [], __('Sifariş № :no yeniləndi.', ['no' => $order->order_no]));
    }

    public function destroy(Request $request, SalesOrder $order): RedirectResponse
    {
        $this->authorizeManual($request, $order, true);
        $no = $order->order_no;
        $order->delete();
        $this->rebuild();

        return redirect()->route('orders.index')->with('popup', ['title' => __('Sifariş silindi'), 'message' => __('Sifariş № :no silindi, rezerv azad olundu.', ['no' => $no])]);
    }

    /**
     * «Sifarişi göndər» — yalnız təhvil-təslim sənədi.
     */
    public function send(Request $request, SalesOrder $order): RedirectResponse
    {
        $this->authorizeManual($request, $order);
        try {
            $note = $this->documents->deliver($order, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->rebuild();

        return redirect()->route('orders.show', $order)->with('popup', $this->popup([[__('Təhvil-təslim sənədi'), $note->doc_no, route('deliveries.show', $note)]]));
    }

    /**
     * «Fakturala» — təhvil-təslim (yoxdursa) + satış fakturası.
     */
    public function invoice(Request $request, SalesOrder $order): RedirectResponse
    {
        $this->authorizeManual($request, $order);
        try {
            $result = $this->documents->invoice($order, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->rebuild();

        return redirect()->route('orders.show', $order)->with('popup', $this->popup($this->docLines($result['delivery'], $result['invoice'])));
    }

    /**
     * Növbəti sifariş nömrəsi: mövcud rəqəmsal nömrələrin ən böyüyü + 1.
     */
    public static function nextOrderNo(): string
    {
        $max = DB::table('sales_orders')->pluck('order_no')
            ->map(fn ($no) => ctype_digit((string) $no) ? (int) $no : 0)->max() ?? 0;

        return (string) ($max + 1);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $created
     */
    private function afterSave(StoreOrderRequest $request, SalesOrder $order, array $created, ?string $message = null): RedirectResponse
    {
        // forma düymələri: «Yadda saxla və göndər» / «Yadda saxla və fakturala»
        $then = $request->input('then');
        try {
            if ($then === 'send') {
                $note = $this->documents->deliver($order, $request->user()->id);
                $created[] = [__('Təhvil-təslim sənədi'), $note->doc_no, route('deliveries.show', $note)];
            } elseif ($then === 'invoice') {
                $result = $this->documents->invoice($order, $request->user()->id);
                $created = array_merge($created, $this->docLines($result['delivery'], $result['invoice']));
            }
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
        $this->rebuild();

        return redirect()->route('orders.show', $order)->with('popup', $this->popup($created, $message));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function docLines(?DeliveryNote $delivery, SalesInvoice $invoice): array
    {
        return array_values(array_filter([
            $delivery ? [__('Təhvil-təslim sənədi'), $delivery->doc_no, route('deliveries.show', $delivery)] : null,
            [__('Satış fakturası'), $invoice->doc_no, route('invoices.show', $invoice)],
        ]));
    }

    /**
     * Popup: «№ X nömrəli … yaradıldı».
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $docs
     * @return array{title: string, message: string|null, docs: list<array{type: string, no: string, url: string}>}
     */
    private function popup(array $docs, ?string $message = null): array
    {
        return [
            'title' => $docs === [] ? __('Yadda saxlandı') : (count($docs) === 1 ? __('Sənəd yaradıldı') : __('Sənədlər yaradıldı')),
            'message' => $message,
            'docs' => array_map(fn ($d) => ['type' => $d[0], 'no' => $d[1], 'url' => $d[2]], $docs),
        ];
    }

    private function form(Request $request, ?SalesOrder $order): View
    {
        $restricted = $request->user()->restrictedManagerId();
        $customers = Customer::query()
            ->when($restricted !== null, fn ($q) => $q->where('manager_id', $restricted))
            ->orderBy('name')->get(['id', 'code', 'name', 'manager_id', 'voen'])
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'manager_id' => $c->manager_id, 'voen' => $c->voen])->values();
        // rezerv stok = real stok − digər açıq sifarişlərdə rezerv olunan
        $reserved = $this->documents->reserved($order?->id);
        $stock = [];
        foreach (DB::table('stock_balances')->get(['product_id', 'warehouse_id', 'qty']) as $r) {
            $stock[$r->product_id][$r->warehouse_id] = round((float) $r->qty - ($reserved[$r->product_id][$r->warehouse_id] ?? 0), 2);
        }
        foreach ($reserved as $productId => $warehouses) {
            foreach ($warehouses as $warehouseId => $qty) {
                $stock[$productId][$warehouseId] ??= round(-$qty, 2);
            }
        }
        // passiv məhsullar yeni sifarişdə görünmür (düzəlişdə mövcud sətirlər qalır)
        $inOrder = $order ? DB::table('sales_order_items')->where('sales_order_id', $order->id)->pluck('product_id')->all() : [];
        $basePrices = app(PriceService::class)->salePrices(null, now()->startOfDay());
        $products = DB::table('products')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $inOrder ?: [0]))->orderBy('name')
            ->get(['id', 'code', 'name', 'sale_price', 'barcode'])
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'barcode' => $p->barcode,
                'price' => round($basePrices[$p->id]['price'] ?? (float) $p->sale_price, 2), 'stock' => (object) ($stock[$p->id] ?? [])])->values();
        $warehouses = Warehouse::query()->sellable()->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'is_main']);

        $lines = $order
            ? DB::table('sales_order_items')->where('sales_order_id', $order->id)->whereNull('campaign_id')->orderBy('row_no')->get()
                ->map(fn ($l) => ['product_id' => $l->product_id, 'op_type' => $l->op_type, 'qty' => (float) $l->qty, 'price' => (float) $l->price, 'discount' => round((float) $l->discount_pct * 100, 2)])->all()
            : [];

        return view('orders.form', [
            'title' => $order ? __('Sifariş № ').$order->order_no.' — '.__('düzəliş') : __('Yeni sifariş'),
            'order' => $order,
            'values' => [
                'date' => old('date', $order?->date?->toDateString() ?? now()->toDateString()),
                'order_no' => old('order_no', $order->order_no ?? self::nextOrderNo()),
                'invoice_no' => old('invoice_no', $order->invoice_no ?? ''),
                'customer_id' => (int) old('customer_id', $order->customer_id ?? 0) ?: null,
                'manager_id' => (int) old('manager_id', $order->manager_id ?? ($restricted ?? 0)) ?: null,
                'warehouse_id' => (int) old('warehouse_id', $order->warehouse_id ?? $warehouses->firstWhere('is_main', true)?->id ?? $warehouses->first()?->id),
                'note' => old('note', $order->note ?? ''),
                'workplace_id' => (int) old('workplace_id', $order->workplace_id ?? Workplace::defaultIdFor($request->user())),
                'lines' => old('lines', $lines),
                'apply_campaigns' => (bool) old('apply_campaigns', $order && $order->campaigns_applied),
                'excluded_campaigns' => array_map('intval', old('excluded_campaigns', $order->campaigns_excluded ?? [])),
            ],
            'customers' => $customers,
            'products' => $products,
            'warehouses' => $warehouses,
            'managers' => Manager::query()->when($restricted !== null, fn ($q) => $q->whereKey($restricted))->orderBy('name')->get(['id', 'name']),
            'managerLocked' => $restricted !== null,
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    private function save(StoreOrderRequest $request, SalesOrder $order): SalesOrder
    {
        $data = $request->validated();
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $restricted = $request->user()->restrictedManagerId();
        $managerId = $restricted ?? ($data['manager_id'] ?? $customer->manager_id);
        $date = Carbon::parse($data['date']);
        $products = DB::table('products')->whereIn('id', array_column($data['lines'], 'product_id'))->get(['id', 'code', 'name'])->keyBy('id');
        $applyCampaigns = $request->boolean('apply_campaigns');
        $excluded = array_values(array_map('intval', $data['excluded_campaigns'] ?? []));

        $order->fill([
            'order_no' => $data['order_no'],
            'invoice_no' => $data['invoice_no'] ?? null,
            'date' => $date->toDateString(),
            'period' => $date->format('Y-m'),
            'customer_id' => $customer->id,
            'customer_code' => $customer->code,
            'customer_name' => $customer->name,
            'manager_id' => $managerId,
            'warehouse_id' => $data['warehouse_id'],
            'workplace_id' => $data['workplace_id'] ?? Workplace::defaultIdFor($request->user()),
            'note' => $data['note'] ?? null,
            'campaigns_applied' => $applyCampaigns,
            'campaigns_excluded' => $applyCampaigns && $excluded !== [] ? $excluded : null,
        ])->save();
        $lines = array_values($data['lines']);
        // «Kampaniya ver» — endirimlər serverdə yenidən hesablanır (formadakı rəqəmlərə etibar edilmir)
        $result = $applyCampaigns ? $this->campaigns->evaluate($customer, $date, $lines, $excluded, $order->id) : null;

        DB::table('sales_order_items')->where('sales_order_id', $order->id)->delete();
        $now = now();
        $rows = [];
        foreach ($lines as $i => $line) {
            $product = $products[$line['product_id']];
            $qty = (float) $line['qty'];
            $price = (float) $line['price'];
            $amount = round($qty * $price, 4);
            $discount = (float) ($line['discount'] ?? 0) / 100;
            $campaignDiscount = (float) ($result['lines'][$i]['campaign_discount'] ?? 0);
            $rows[] = [
                'product_id' => $product->id, 'product_code' => $product->code, 'product_name' => $product->name, 'op_type' => $line['op_type'],
                'qty' => $qty, 'price' => $price, 'amount' => $amount, 'discount_pct' => $discount,
                'campaign_discount' => $campaignDiscount, 'net_amount' => round($amount * (1 - $discount) - $campaignDiscount, 4), 'campaign_id' => null,
                'discounts' => $result['lines'][$i]['discounts'] ?? [],
            ];
        }
        foreach ($result['gifts'] ?? [] as $gift) {
            $rows[] = [
                'product_id' => $gift['product_id'], 'product_code' => $gift['product_code'], 'product_name' => $gift['product_name'], 'op_type' => 'gift',
                'qty' => $gift['qty'], 'price' => 0, 'amount' => 0, 'discount_pct' => 0, 'campaign_discount' => 0, 'net_amount' => 0,
                'campaign_id' => $gift['campaign_id'], 'discounts' => [],
            ];
        }
        $total = 0.0;
        foreach ($rows as $i => $row) {
            $discounts = $row['discounts'];
            unset($row['discounts']);
            $total += $row['op_type'] === 'sale' ? $row['net_amount'] : 0;
            $itemId = DB::table('sales_order_items')->insertGetId($row + ['sales_order_id' => $order->id, 'row_no' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($discounts as $d) {
                DB::table('sales_item_campaigns')->insert([
                    'sales_order_id' => $order->id, 'sales_order_item_id' => $itemId, 'campaign_id' => $d['campaign_id'],
                    'campaign_code' => $d['code'], 'campaign_name' => $d['name'], 'amount' => $d['amount'], 'rate' => $d['rate'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        // cəmlər arxa planda yenilənənə qədər siyahıda məbləğ dərhal görünsün
        $order->forceFill(['line_count' => count($rows), 'total_amount' => round($total, 4)])->save();

        return $order;
    }

    private function authorizeManual(Request $request, SalesOrder $order, bool $mustBeOpen = false): void
    {
        abort_unless($order->source === 'manual', 403, __('İmportdan gələn sifariş burada dəyişdirilmir — faylı yenidən import edin.'));
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $order->manager_id !== $restricted, 403);
        abort_if($mustBeOpen && $order->status !== 'open', 403, __('Təhvil verilmiş sifariş dəyişdirilmir — əvvəlcə təhvil-təslim sənədini ləğv edin.'));
    }

    private function rebuild(): void
    {
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();
    }
}
