<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\SalesOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
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
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.op_type' => ['required', Rule::in(['sale', 'gift', 'internal'])],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'lines.*.price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
