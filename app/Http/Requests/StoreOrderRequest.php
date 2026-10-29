<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Services\BatchService;
use App\Support\Fmt;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
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
        /** @var SalesOrder|null $order */
        $order = $this->route('order');

        return [
            'date' => ['required', 'date'],
            'order_no' => ['required', 'string', 'max:64', Rule::unique('sales_orders', 'order_no')->ignore($order?->id)],
            'invoice_no' => ['nullable', 'string', 'max:64'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'manager_id' => ['nullable', 'integer', 'exists:managers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.op_type' => ['required', Rule::in(['sale', 'gift', 'internal'])],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'lines.*.price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'apply_campaigns' => ['nullable', 'boolean'],
            'excluded_campaigns' => ['nullable', 'array'],
            'excluded_campaigns.*' => ['integer'],
            'then' => ['nullable', Rule::in(['send', 'invoice'])],
        ];
    }

    /**
     * Menecer yalnız öz müştərisinə sifariş yaza bilər.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $restricted = $this->user()->restrictedManagerId();
            if ($restricted !== null && Customer::query()->whereKey($this->integer('customer_id'))->value('manager_id') !== $restricted) {
                $validator->errors()->add('customer_id', __('Bu müştəri sizə bağlı deyil.'));
            }
            // qiymət nəzarəti: məhsul kartındakı minimum qiymət və maksimum endirim
            $lines = collect($this->input('lines', []))->filter(fn ($l) => ($l['op_type'] ?? 'sale') === 'sale' && is_numeric($l['product_id'] ?? null));
            $limits = DB::table('products')->whereIn('id', $lines->pluck('product_id')->all() ?: [0])->get(['id', 'code', 'min_price', 'max_discount_pct'])->keyBy('id');
            // vaxtı bitmiş mal satılmır: qeydiyyatlı partiyası olan mal üçün sifariş vaxtı bitməmiş qalığı keçə bilməz
            $valid = app(BatchService::class)->validStock();
            foreach ($lines->groupBy(fn ($l) => (int) $l['product_id']) as $productId => $group) {
                if (isset($valid[$productId]) && $valid[$productId]['expired'] > 0 && $group->sum(fn ($l) => (float) $l['qty']) > $valid[$productId]['valid'] + 1e-6) {
                    $validator->errors()->add('lines.'.$group->keys()->first().'.qty', __(':code: vaxtı bitməmiş qalıq yalnız :qty — vaxtı bitmiş mal satılmır.', [
                        'code' => $limits[$productId]->code ?? $productId, 'qty' => rtrim(rtrim(number_format(max($valid[$productId]['valid'], 0), 2, ',', ''), '0'), ','),
                    ]));
                }
            }
            foreach ($lines as $i => $line) {
                $p = $limits[(int) $line['product_id']] ?? null;
                $discount = (float) ($line['discount'] ?? 0);
                $unit = (float) ($line['price'] ?? 0) * (1 - $discount / 100);
                if ($p && $p->max_discount_pct !== null && $discount > (float) $p->max_discount_pct + 1e-9) {
                    $validator->errors()->add("lines.{$i}.discount", __(':code: endirim ən çox :max % ola bilər.', ['code' => $p->code, 'max' => rtrim(rtrim(number_format((float) $p->max_discount_pct, 2, ',', ''), '0'), ',')]));
                }
                if ($p && $p->min_price !== null && $unit + 1e-6 < (float) $p->min_price) {
                    $validator->errors()->add("lines.{$i}.price", __(':code: qiymət minimum satış qiymətindən (:min) aşağıdır.', ['code' => $p->code, 'min' => Fmt::money($p->min_price)]));
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => __('Tarix'), 'order_no' => __('Sifariş №'), 'customer_id' => __('Müştəri'), 'warehouse_id' => __('Anbar'),
            'lines' => __('Məhsullar'), 'lines.*.product_id' => __('Mal'), 'lines.*.qty' => __('Miqdar'), 'lines.*.price' => __('Qiymət'),
        ];
    }
}
