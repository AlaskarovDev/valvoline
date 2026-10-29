<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qiymət kartlarından aktiv qiymətin seçilməsi.
 * Prioritet: müştəriyə özəl kart → müştərinin qiymət növü → əsas (default) qiymət növü → malın kartındakı satış qiyməti.
 * Eyni səviyyədə: miqdar həddi ödənən ən böyük min_qty, sonra ən son başlama tarixi.
 * Qaytarılan qiymət həmişə əsas vahid üçün və ƏDV daxildir (sənədlər ƏDV daxil qiymətlə işləyir).
 */
class PriceService
{
    /**
     * @return array{price: float, net: float, card_id: int|null, source: string, label: string, vat_included: bool, raw: float}|null
     */
    public function salePrice(int $productId, ?Customer $customer, Carbon $date, float $qty = 1): ?array
    {
        return $this->salePrices($customer, $date, [$productId], $qty)[$productId] ?? null;
    }

    /**
     * Bir müştəri və tarix üçün bütün (və ya verilmiş) malların satış qiyməti.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, array{price: float, net: float, card_id: int|null, source: string, label: string, vat_included: bool, raw: float}>
     */
    public function salePrices(?Customer $customer, Carbon $date, ?array $productIds = null, float $qty = 1): array
    {
        $defaultList = DB::table('price_lists')->where('type', 'sale')->where('is_default', true)->value('id');
        $customerList = $customer?->price_list_id;
        $lists = DB::table('price_lists')->pluck('name', 'id');
        $products = DB::table('products')->when($productIds !== null, fn ($q) => $q->whereIn('id', $productIds ?: [0]))
            ->get(['id', 'sale_price', 'vat_rate', 'vat_mode'])->keyBy('id');

        $cards = $this->activeCards('sale', $date, $productIds, $qty)
            ->filter(fn ($c) => $c->customer_id === null || ($customer && (int) $c->customer_id === $customer->id))
            ->groupBy('product_id');

        $out = [];
        foreach ($products as $id => $product) {
            $factor = $product->vat_mode === 'standard' ? 1 + (float) $product->vat_rate / 100 : 1.0;
            $candidates = $cards[$id] ?? collect();
            $pick = $candidates->first(fn ($c) => $c->customer_id !== null)
                ?? ($customerList ? $candidates->first(fn ($c) => (int) $c->price_list_id === (int) $customerList && $c->customer_id === null) : null)
                ?? $candidates->first(fn ($c) => (int) $c->price_list_id === (int) $defaultList && $c->customer_id === null)
                ?? $candidates->first(fn ($c) => $c->price_list_id === null && $c->customer_id === null);

            if ($pick) {
                $gross = $pick->vat_included ? (float) $pick->price : (float) $pick->price * $factor;
                $source = $pick->customer_id !== null ? 'customer' : 'list';
                $label = $pick->customer_id !== null ? __('Müştəriyə özəl qiymət') : ($lists[$pick->price_list_id] ?? __('Qiymət kartı'));
                $out[$id] = ['price' => round($gross, 4), 'net' => round($gross / $factor, 4), 'card_id' => (int) $pick->id, 'source' => $source,
                    'label' => $label, 'vat_included' => (bool) $pick->vat_included, 'raw' => (float) $pick->price];
            } elseif ((float) $product->sale_price > 0) {
                $out[$id] = ['price' => (float) $product->sale_price, 'net' => round((float) $product->sale_price / $factor, 4), 'card_id' => null,
                    'source' => 'product', 'label' => __('Mal kartındakı qiymət'), 'vat_included' => true, 'raw' => (float) $product->sale_price];
            }
        }

        return $out;
    }

    /**
     * Alış qiyməti: aktiv alış kartı, yoxdursa son idxal partiyasının mayası.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, array{price: float, source: string}>
     */
    public function purchasePrices(Carbon $date, ?array $productIds = null): array
    {
        $out = [];
        foreach (DB::table('purchase_lines')->whereNotNull('product_id')->where('unit_cost', '>', 0)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: [0]))
            ->orderBy('date')->orderBy('id')->get(['product_id', 'unit_cost']) as $row) {
            $out[$row->product_id] = ['price' => (float) $row->unit_cost, 'source' => 'last_purchase'];
        }
        $vat = DB::table('products')->when($productIds !== null, fn ($q) => $q->whereIn('id', $productIds ?: [0]))
            ->get(['id', 'vat_rate', 'vat_mode'])->keyBy('id');
        foreach ($this->activeCards('purchase', $date, $productIds)->whereNull('customer_id')->groupBy('product_id') as $id => $cards) {
            $card = $cards->first();
            $p = $vat[$id] ?? null;
            $factor = $p && $p->vat_mode === 'standard' ? 1 + (float) $p->vat_rate / 100 : 1.0;
            $out[$id] = ['price' => round($card->vat_included ? (float) $card->price : (float) $card->price * $factor, 4), 'source' => 'card'];
        }

        return $out;
    }

    /**
     * Tarixdə qüvvədə olan kartlar (əsas vahid üçün), ən uyğun birinci: böyük min_qty, son başlama tarixi.
     *
     * @param  list<int>|null  $productIds
     * @return Collection<int, object>
     */
    public function activeCards(string $type, Carbon $date, ?array $productIds = null, float $qty = 1)
    {
        $day = $date->toDateString();

        return DB::table('price_cards')
            ->where('type', $type)->where('is_active', true)->whereNull('product_unit_id')
            ->whereDate('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->where('min_qty', '<=', $qty)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: [0]))
            ->orderByDesc('min_qty')->orderByDesc('valid_from')->orderByDesc('id')
            ->get();
    }
}
