<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sifarişə kampaniya tətbiqi (reporting-portal CampaignDocumentEngine məntiqi):
 * uyğun kampaniyalar prioritetə görə sıralanır, eyni prioritet qrupundan yalnız biri tətbiq olunur,
 * hər sətirdə «qalan məbləğ» axını — hər endirim əvvəlkilərdən sonra qalan məbləğdən (P3) və ya brütdən (P1) hesablanır.
 * Hədiyyə kampaniyaları ayrıca pulsuz mal sətri yaradır.
 */
class CampaignEngine
{
    /**
     * @param  list<array{product_id: int|null, op_type?: string, qty: float|int|string, price: float|int|string, discount?: float|int|string|null}>  $lines
     * @param  list<int>  $excluded  istifadəçinin sifarişdən çıxardığı kampaniyalar
     * @return array{lines: list<array{gross: float, net_before: float, discounts: list<array{campaign_id: int, code: string, name: string, amount: float, rate: float}>, campaign_discount: float, net: float}>, gifts: list<array{campaign_id: int, code: string, name: string, product_id: int, product_code: string, product_name: string, qty: float, line: int}>, campaigns: list<array{id: int, code: string, name: string, kind: string, applied: bool, reason: string|null}>, total_discount: float}
     */
    public function evaluate(Customer $customer, Carbon $date, array $lines, array $excluded = [], ?int $orderId = null): array
    {
        $products = DB::table('products')->whereIn('id', array_filter(array_column($lines, 'product_id')))
            ->get(['id', 'code', 'name', 'product_group_id', 'category_id', 'series'])->keyBy('id');

        $state = [];
        foreach (array_values($lines) as $i => $line) {
            $qty = (float) $line['qty'];
            $gross = round($qty * (float) $line['price'], 4);
            $net = round($gross * (1 - (float) ($line['discount'] ?? 0) / 100), 4);
            $state[$i] = [
                'product' => $products[$line['product_id'] ?? 0] ?? null, 'sale' => ($line['op_type'] ?? 'sale') === 'sale',
                'qty' => $qty, 'gross' => $gross, 'net_before' => $net, 'remaining' => $net, 'discounts' => [],
            ];
        }

        $gifts = [];
        $report = [];
        $usedGroups = [];
        foreach ($this->candidates($customer, $date, $orderId) as $campaign) {
            $entry = ['id' => $campaign->id, 'code' => $campaign->code, 'name' => $campaign->name, 'kind' => $campaign->kind, 'applied' => false, 'reason' => null];
            if (in_array($campaign->id, $excluded, true)) {
                $report[] = ['reason' => __('Sifarişdən çıxarılıb')] + $entry;

                continue;
            }
            if ($campaign->priority_group && isset($usedGroups[$campaign->priority_group])) {
                $report[] = ['reason' => __('Eyni qrupdan :code tətbiq olunub', ['code' => $usedGroups[$campaign->priority_group]])] + $entry;

                continue;
            }
            if ($campaign->limit_reached) {
                $report[] = ['reason' => __('Müştəri üzrə tətbiq limiti dolub')] + $entry;

                continue;
            }
            $targets = array_keys(array_filter($state, fn ($s) => $s['sale'] && $s['product'] && $this->productMatches($campaign, $s['product'])));
            if ($targets === []) {
                $report[] = ['reason' => __('Sifarişdə kampaniya malı yoxdur')] + $entry;

                continue;
            }

            $applied = $campaign->isGift()
                ? $this->applyGift($campaign, $state, $targets, $gifts)
                : $this->applyDiscount($campaign, $state, $targets);
            if ($applied) {
                $entry['applied'] = true;
                if ($campaign->priority_group) {
                    $usedGroups[$campaign->priority_group] = $campaign->code;
                }
            } else {
                $entry['reason'] = __('Şərt ödənmir');
            }
            $report[] = $entry;
        }

        $out = [];
        $total = 0.0;
        foreach ($state as $s) {
            $discount = round($s['net_before'] - $s['remaining'], 2);
            $total += $discount;
            $out[] = [
                'gross' => round($s['gross'], 2), 'net_before' => round($s['net_before'], 2), 'discounts' => $s['discounts'],
                'campaign_discount' => $discount, 'net' => round($s['remaining'], 2),
            ];
        }

        return ['lines' => $out, 'gifts' => $gifts, 'campaigns' => $report, 'total_discount' => round($total, 2)];
    }

    /**
     * Tarixə, statusa və müştəriyə görə uyğun kampaniyalar (PRIORITY, sonra kod üzrə).
     *
     * @return Collection<int, Campaign>
     */
    public function candidates(Customer $customer, Carbon $date, ?int $orderId = null): Collection
    {
        return Campaign::query()
            ->where('status', 'approved')->where('is_active', true)
            ->whereDate('begin_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->orderBy('priority')->orderBy('code')->get()
            ->filter(fn (Campaign $c) => $this->customerMatches($c, $customer))
            ->each(function (Campaign $c) use ($customer, $orderId) {
                $c->limit_reached = $c->apply_limit > 0 && $this->usageCount($c, $customer, $orderId) >= $c->apply_limit;
            })->values();
    }

    public function usageCount(Campaign $campaign, Customer $customer, ?int $exceptOrderId = null): int
    {
        $orders = DB::table('sales_item_campaigns')->where('campaign_id', $campaign->id)->pluck('sales_order_id')
            ->merge(DB::table('sales_order_items')->where('campaign_id', $campaign->id)->pluck('sales_order_id'))->unique();

        return DB::table('sales_orders')->whereIn('id', $orders->all() ?: [0])->where('customer_id', $customer->id)
            ->when($exceptOrderId, fn ($q) => $q->where('id', '<>', $exceptOrderId))->count();
    }

    public function customerMatches(Campaign $campaign, Customer $customer): bool
    {
        if (in_array($customer->id, array_map('intval', $campaign->customer_excludes ?? []), true)) {
            return false;
        }
        $ids = array_map('intval', $campaign->customer_filter ?? []);

        return match ($campaign->customer_scope) {
            'customers' => in_array($customer->id, $ids, true),
            'managers' => $customer->manager_id !== null && in_array((int) $customer->manager_id, $ids, true),
            default => true,
        };
    }

    public function productMatches(Campaign $campaign, object $product): bool
    {
        if (in_array((int) $product->id, array_map('intval', $campaign->excluded_products ?? []), true)) {
            return false;
        }
        $filter = $campaign->product_filter ?? [];

        return match ($campaign->product_scope) {
            'products' => $this->productRow($campaign, (int) $product->id) !== null,
            'groups' => in_array((int) $product->product_group_id, array_map('intval', $filter), true),
            'categories' => in_array((int) $product->category_id, array_map('intval', $filter), true),
            'series' => $product->series !== null && in_array($product->series, $filter, true),
            default => true,
        };
    }

    /**
     * @return array{id: int, rate?: float|null, price?: float|null}|null
     */
    private function productRow(Campaign $campaign, int $productId): ?array
    {
        foreach ($campaign->product_filter ?? [] as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $productId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Hədd / pillə bazası: miqdar və ya məbləğ (ƏDV əmsalı ilə).
     *
     * @param  array<int, array<string, mixed>>  $state
     * @param  list<int>  $indexes
     */
    private function basis(Campaign $campaign, array $state, array $indexes): float
    {
        $sum = 0.0;
        foreach ($indexes as $i) {
            $sum += $campaign->calc_basis === 'amount' ? $state[$i]['net_before'] * $campaign->vat_multiplier : $state[$i]['qty'];
        }

        return $sum;
    }

    /**
     * @return array{from: float, to: float|null, value: float}|null
     */
    private function tier(Campaign $campaign, float $basis): ?array
    {
        $matched = null;
        foreach ($campaign->tiers ?? [] as $tier) {
            $from = (float) $tier['from'];
            $to = isset($tier['to']) && $tier['to'] !== '' && $tier['to'] !== null ? (float) $tier['to'] : null;
            if ($basis + 0.0001 >= $from && ($to === null || $basis < $to)) {
                $matched = ['from' => $from, 'to' => $to, 'value' => (float) $tier['value']];
            }
        }

        return $matched;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @param  list<int>  $targets
     */
    private function applyDiscount(Campaign $campaign, array &$state, array $targets): bool
    {
        $rate = (float) $campaign->discount_rate;
        $cap = false;
        switch ($campaign->kind) {
            case 'qty_threshold_discount':
            case 'amount_threshold_discount':
                if ($this->basis($campaign, $state, $targets) + 0.0001 < (float) $campaign->threshold_value) {
                    return false;
                }
                break;
            case 'tiered_discount':
                $scope = $campaign->tier_scope === 'document' ? array_keys(array_filter($state, fn ($s) => $s['sale'])) : $targets;
                $tier = $this->tier($campaign, $this->basis($campaign, $state, $scope));
                if ($tier === null) {
                    return false;
                }
                $rate = $tier['value'];
                $cap = $campaign->tier_calc === 'cap';
                break;
            case 'max_discount_cap':
                $cap = true;
                break;
        }

        $hit = false;
        foreach ($targets as $i) {
            $s = &$state[$i];
            $base = $campaign->formula_base === 'P1' ? $s['gross'] : $s['remaining'];
            $row = $this->productRow($campaign, (int) $s['product']->id);
            $amount = match (true) {
                // hədəf qiymət: vahid qiymət hədəfə enənə qədər endirim
                $campaign->kind === 'target_price' => isset($row['price']) && $row['price'] !== null && $row['price'] !== '' ? max(0, $s['remaining'] - $s['qty'] * (float) $row['price']) : 0.0,
                // tavan: ümumi endirim brütün rate %-nə çatana qədər tamamlanır
                $cap => max(0, $s['remaining'] - $s['gross'] * (1 - $rate / 100)),
                $campaign->kind === 'simple_discount' && isset($row['rate']) && $row['rate'] !== null && $row['rate'] !== '' => $base * (float) $row['rate'] / 100,
                default => $base * $rate / 100,
            };
            $amount = round(min($amount, $s['remaining']), 2);
            if ($amount > 0) {
                $s['discounts'][] = [
                    'campaign_id' => $campaign->id, 'code' => $campaign->code, 'name' => $campaign->name,
                    'amount' => $amount, 'rate' => $s['gross'] > 0 ? round($amount / $s['gross'] * 100, 2) : 0.0,
                ];
                $s['remaining'] = round($s['remaining'] - $amount, 4);
                $hit = true;
            }
            unset($s);
        }

        return $hit;
    }

    /**
     * Hədiyyə: proporsional TRUNC(baza/N)*M, pilləli — sabit miqdar, pilləli-çoxqat — TRUNC(baza/from)*miqdar.
     *
     * @param  array<int, array<string, mixed>>  $state
     * @param  list<int>  $targets
     * @param  list<array<string, mixed>>  $gifts
     */
    private function applyGift(Campaign $campaign, array $state, array $targets, array &$gifts): bool
    {
        $product = $campaign->giftProduct;
        if ($product === null) {
            return false;
        }
        $basis = $this->basis($campaign, $state, $targets);
        if ($campaign->gift_mode === 'tiered' || $campaign->gift_mode === 'tiered_multiple') {
            $tier = $this->tier($campaign, $basis);
            $qty = $tier === null ? 0 : ($campaign->gift_mode === 'tiered' ? $tier['value'] : floor($basis / max($tier['from'], 0.0001)) * $tier['value']);
        } else {
            $qty = $basis + 0.0001 < (float) $campaign->threshold_value || (float) $campaign->gift_divisor <= 0
                ? 0 : floor(($basis + 0.0001) / (float) $campaign->gift_divisor) * (float) $campaign->gift_qty;
        }
        if ($qty <= 0) {
            return false;
        }
        $gifts[] = [
            'campaign_id' => $campaign->id, 'code' => $campaign->code, 'name' => $campaign->name,
            'product_id' => $product->id, 'product_code' => $product->code, 'product_name' => $product->name,
            'qty' => (float) $qty, 'line' => $targets[0],
        ];

        return true;
    }
}
