<?php

namespace App\Http\Requests;

use App\Models\Campaign;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Campaign|null $campaign */
        $campaign = $this->route('campaign');

        return [
            'code' => ['nullable', 'string', 'max:25', 'regex:/^[A-Za-z0-9._\-+*\/]+$/', Rule::unique('campaigns', 'code')->ignore($campaign?->id)],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', Rule::in(Campaign::KINDS)],
            'calc_basis' => ['nullable', Rule::in(['qty', 'amount'])],
            'formula_base' => ['required', Rule::in(['P3', 'P1'])],
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_value' => ['nullable', 'numeric', 'min:0'],
            'tier_calc' => ['nullable', Rule::in(['percent', 'cap'])],
            'tier_scope' => ['nullable', Rule::in(['products', 'document'])],
            'tiers' => ['nullable', 'array', 'max:50'],
            'tiers.*.from' => ['required', 'numeric', 'min:0'],
            'tiers.*.to' => ['nullable', 'numeric', 'gt:tiers.*.from'],
            'tiers.*.value' => ['required', 'numeric', 'min:0'],
            'gift_mode' => ['nullable', Rule::in(['proportional', 'tiered', 'tiered_multiple'])],
            'gift_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'gift_qty' => ['nullable', 'numeric', 'min:0'],
            'gift_divisor' => ['nullable', 'numeric', 'min:0'],
            'vat_multiplier' => ['required', 'numeric', 'gt:0', 'max:10'],
            'product_scope' => ['required', Rule::in(['all', 'products', 'groups', 'categories', 'series'])],
            'products' => ['nullable', 'array', 'max:2000'],
            'products.*.id' => ['required', 'integer', 'exists:products,id'],
            'products.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'products.*.price' => ['nullable', 'numeric', 'min:0'],
            'groups' => ['nullable', 'array'], 'groups.*' => ['integer', 'exists:product_groups,id'],
            'categories' => ['nullable', 'array'], 'categories.*' => ['integer', 'exists:categories,id'],
            'series' => ['nullable', 'array'], 'series.*' => ['string', 'max:100'],
            'excluded_products' => ['nullable', 'array'], 'excluded_products.*' => ['integer', 'exists:products,id'],
            'customer_scope' => ['required', Rule::in(['all', 'customers', 'managers'])],
            'customers' => ['nullable', 'array', 'max:5000'], 'customers.*' => ['integer', 'exists:customers,id'],
            'managers' => ['nullable', 'array'], 'managers.*' => ['integer', 'exists:managers,id'],
            'customer_excludes' => ['nullable', 'array'], 'customer_excludes.*' => ['integer', 'exists:customers,id'],
            'begin_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:begin_date'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:999'],
            'priority_group' => ['nullable', 'string', 'max:30'],
            'apply_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'estimated_budget' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Növə görə şərtlər (portal validateConditionKind).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $kind = $this->input('kind');
            $rate = (float) $this->input('discount_rate');
            $tiers = $this->input('tiers', []);
            $products = $this->input('products', []);
            $err = fn (string $field, string $message) => $validator->errors()->add($field, $message);
            $ratedProducts = $products !== [] && collect($products)->every(fn ($p) => ($p['rate'] ?? '') !== '' && $p['rate'] !== null);

            if ($kind === 'simple_discount' && ($rate <= 0 || $rate > 100) && ! ($this->input('product_scope') === 'products' && $ratedProducts)) {
                $err('discount_rate', __('Endirim faizi 0-dan böyük və 100-dən kiçik olmalıdır (və ya hər mala ayrıca faiz yazın).'));
            }
            if (in_array($kind, ['qty_threshold_discount', 'amount_threshold_discount', 'invoice_bottom_discount', 'max_discount_cap'], true) && ($rate <= 0 || $rate > 100)) {
                $err('discount_rate', __('Endirim faizi 0-dan böyük və 100-dən kiçik olmalıdır.'));
            }
            if (in_array($kind, ['qty_threshold_discount', 'amount_threshold_discount'], true) && (float) $this->input('threshold_value') <= 0) {
                $err('threshold_value', __('Hədd 0-dan böyük olmalıdır.'));
            }
            $tiered = $kind === 'tiered_discount' || (in_array($kind, ['qty_gift', 'amount_gift'], true) && in_array($this->input('gift_mode'), ['tiered', 'tiered_multiple'], true));
            if ($tiered && $tiers === []) {
                $err('tiers', __('Ən azı bir pillə daxil edin.'));
            }
            if ($kind === 'tiered_discount' && $this->input('tier_calc') !== 'cap' && collect($tiers)->contains(fn ($t) => (float) ($t['value'] ?? 0) <= 0 || (float) $t['value'] > 100)) {
                $err('tiers', __('Pillə faizi 0–100 arasında olmalıdır.'));
            }
            if (in_array($kind, ['qty_gift', 'amount_gift'], true)) {
                if (! $this->input('gift_product_id')) {
                    $err('gift_product_id', __('Hədiyyə malını seçin.'));
                }
                if ($tiered && collect($tiers)->contains(fn ($t) => (float) ($t['value'] ?? 0) <= 0)) {
                    $err('tiers', __('Hər pillədə hədiyyə miqdarı 0-dan böyük olmalıdır.'));
                }
                if (! $tiered && ((float) $this->input('gift_qty') <= 0 || (float) $this->input('gift_divisor') <= 0)) {
                    $err('gift_qty', __('«Hər N-ə M hədiyyə» üçün N və M 0-dan böyük olmalıdır.'));
                }
            }
            if ($kind === 'target_price' && ($this->input('product_scope') !== 'products' || collect($products)->contains(fn ($p) => ($p['price'] ?? '') === '' || $p['price'] === null))) {
                $err('products', __('Hədəf qiymət üçün mallar ayrıca seçilməli və hər birinə qiymət yazılmalıdır.'));
            }
            $scopeField = ['products' => 'products', 'groups' => 'groups', 'categories' => 'categories', 'series' => 'series'][$this->input('product_scope')] ?? null;
            if ($scopeField && $this->input($scopeField, []) === []) {
                $err($scopeField, __('Kampaniya mallarını seçin.'));
            }
            $customerField = ['customers' => 'customers', 'managers' => 'managers'][$this->input('customer_scope')] ?? null;
            if ($customerField && $this->input($customerField, []) === []) {
                $err($customerField, __('Kampaniya müştərilərini seçin.'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Ad'), 'code' => __('Kod'), 'kind' => __('Kampaniya növü'), 'begin_date' => __('Başlama tarixi'), 'end_date' => __('Bitmə tarixi'),
            'discount_rate' => __('Endirim faizi'), 'threshold_value' => __('Hədd'), 'tiers.*.from' => __('Pillə: başlanğıc'), 'tiers.*.to' => __('Pillə: son'),
            'tiers.*.value' => __('Pillə dəyəri'), 'gift_product_id' => __('Hədiyyə malı'), 'vat_multiplier' => __('ƏDV əmsalı'),
        ];
    }
}
