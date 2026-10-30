<?php

namespace App\Http\Requests;

use App\Http\Controllers\OrderEntryController;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Services\BatchService;
use App\Services\CreditControl;
use App\Services\NumberingService;
use App\Support\Fmt;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    /**
     * Rəhbərin təsdiqinə göndərilən (yumşaq) qayda pozuntuları: kredit limiti, maksimum endirim, minimum qiymət.
     *
     * @var list<string>
     */
    public array $approvalReasons = [];

    public float $saleAmount = 0.0;

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
            'order_no' => [Rule::requiredIf($order !== null || app(NumberingService::class)->mode('sales_order') === 'manual'), 'nullable', 'string', 'max:64', Rule::unique('sales_orders', 'order_no')->ignore($order?->id)],
            'order_no_offered' => ['nullable', 'string', 'max:64'],
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
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'apply_campaigns' => ['nullable', 'boolean'],
            'excluded_campaigns' => ['nullable', 'array'],
            'excluded_campaigns.*' => ['integer'],
            'then' => ['nullable', Rule::in(['send', 'invoice'])],
            'credit_override' => ['nullable', 'boolean'],
            'request_approval' => ['nullable', 'boolean'],
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
            // kredit nəzarəti: bloklu müştəri — həmişə qadağa; kredit limiti — rəhbər keçə bilər və ya təsdiqə göndərilir
            $soft = [];
            $customer = Customer::query()->find($this->integer('customer_id'));
            if ($customer) {
                $amount = collect(OrderEntryController::toBaseUnits(collect($this->input('lines', []))
                    ->filter(fn ($l) => is_numeric($l['qty'] ?? null) && is_numeric($l['price'] ?? null) && ($l['op_type'] ?? 'sale') === 'sale')->all()))
                    ->sum(fn ($l) => (float) $l['qty'] * (float) $l['price'] * (1 - (float) ($l['discount'] ?? 0) / 100));
                $this->saleAmount = round($amount, 2);
                $hard = app(CreditControl::class)->violations($customer, $this->saleAmount, $this->route('order')?->id, true);
                foreach ($hard as $message) {
                    $validator->errors()->add('customer_id', $message);
                }
                foreach (array_diff(app(CreditControl::class)->violations($customer, $this->saleAmount, $this->route('order')?->id), $hard) as $message) {
                    $soft[] = ['customer_id', $message];
                }
            }
            // qiymət nəzarəti: məhsul kartındakı minimum qiymət və maksimum endirim
            $lines = collect(OrderEntryController::toBaseUnits(collect($this->input('lines', []))
                ->filter(fn ($l) => is_numeric($l['product_id'] ?? null) && is_numeric($l['qty'] ?? null) && is_numeric($l['price'] ?? null))->all()))
                ->filter(fn ($l) => ($l['op_type'] ?? 'sale') === 'sale');
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
                    $soft[] = ["lines.{$i}.discount", __(':code: endirim ən çox :max % ola bilər.', ['code' => $p->code, 'max' => rtrim(rtrim(number_format((float) $p->max_discount_pct, 2, ',', ''), '0'), ',')])];
                }
                if ($p && $p->min_price !== null && $unit + 1e-6 < (float) $p->min_price) {
                    $soft[] = ["lines.{$i}.price", __(':code: qiymət minimum satış qiymətindən (:min) aşağıdır.', ['code' => $p->code, 'min' => Fmt::money($p->min_price)])];
                }
            }
            // yumşaq qaydalar: rəhbər «icazə verirəm» ilə keçir, digərləri sifarişi təsdiqə göndərə bilər
            if ($soft && ! ($this->user()->can('sales.manage') && $this->boolean('credit_override'))) {
                if ($this->boolean('request_approval')) {
                    $this->approvalReasons = array_column($soft, 1);
                } else {
                    foreach ($soft as [$key, $message]) {
                        $validator->errors()->add($key, $message);
                    }
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
