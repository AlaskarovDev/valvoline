<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('products', 'code')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[0-9A-Za-z\-]+$/'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'manufacturer_id' => ['nullable', 'integer', 'exists:manufacturers,id'],
            'product_class_id' => ['nullable', 'integer', 'exists:product_classes,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'product_group_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'series' => ['nullable', 'string', 'max:100'],
            'viscosity' => ['nullable', 'string', 'max:50'],
            'volume_l' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'vat_mode' => ['required', Rule::in(['standard', 'zero', 'exempt', 'not_subject'])],
            'excise_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'specifications' => ['nullable', 'string', 'max:255'],
            'application' => ['nullable', 'string', 'max:100'],
            'alternatives' => ['nullable', 'array', 'max:50'],
            'alternatives.*' => ['integer', 'exists:products,id'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'hs_code' => ['nullable', 'string', 'max:20'],
            'country_of_origin' => ['nullable', 'string', 'max:64'],
            'shelf_life_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'min_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'max_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'prices_vat_included' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
            'units' => ['nullable', 'array', 'max:20'],
            'units.*.unit_id' => ['required', 'integer', 'exists:units,id', 'distinct'],
            'units.*.factor' => ['required', 'numeric', 'gt:0'],
            'units.*.barcode' => ['nullable', 'string', 'max:64', 'regex:/^[0-9A-Za-z\-]+$/'],
            'units.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'sale_unit' => ['nullable', 'integer'],
            'purchase_unit' => ['nullable', 'integer'],
            'extra_barcodes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Barkodlar unikal olmalıdır (başqa malda istifadə olunmayıb), əlavə vahid əsas vahidlə eyni ola bilməz.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $productId = $this->route('product')?->id;
            $codes = array_filter(array_merge(
                [$this->input('barcode')],
                array_column($this->input('units', []), 'barcode'),
                preg_split('/[\s,;]+/', (string) $this->input('extra_barcodes'), -1, PREG_SPLIT_NO_EMPTY),
            ));
            if (count($codes) !== count(array_unique($codes))) {
                $validator->errors()->add('barcode', __('Eyni barkod iki dəfə yazılıb.'));
            }
            $taken = DB::table('product_barcodes')->whereIn('barcode', $codes ?: ['-'])->when($productId, fn ($q) => $q->where('product_id', '<>', $productId))->pluck('barcode')
                ->merge(DB::table('products')->whereIn('barcode', $codes ?: ['-'])->when($productId, fn ($q) => $q->where('id', '<>', $productId))->pluck('barcode'));
            if ($taken->isNotEmpty()) {
                $validator->errors()->add('barcode', __('Barkod başqa malda istifadə olunur: :codes', ['codes' => $taken->unique()->implode(', ')]));
            }
            if (collect($this->input('units', []))->contains(fn ($u) => (int) ($u['unit_id'] ?? 0) === (int) $this->input('base_unit_id'))) {
                $validator->errors()->add('units', __('Əlavə vahid əsas vahidlə eyni ola bilməz.'));
            }
            if ($this->filled('max_stock_qty') && $this->filled('min_stock_qty') && (float) $this->input('max_stock_qty') < (float) $this->input('min_stock_qty')) {
                $validator->errors()->add('max_stock_qty', __('Maksimum stok minimumdan kiçik ola bilməz.'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => __('Kod'), 'name' => __('Ad'), 'barcode' => __('Barkod'), 'base_unit_id' => __('Əsas vahid'),
            'units.*.unit_id' => __('Vahid'), 'units.*.factor' => __('Əmsal'), 'units.*.barcode' => __('Barkod'),
        ];
    }
}
