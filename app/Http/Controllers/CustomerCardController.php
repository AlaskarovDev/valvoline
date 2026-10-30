<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\NumberingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Müştəri kartı: yaratmaq / düzəliş (əlaqə, region, ödəniş müddəti, kredit limiti, status).
 * Admin istənilən müştərini, menecer yalnız özünə bağlı müştərini idarə edir.
 */
class CustomerCardController extends Controller
{
    public function create(Request $request): View
    {
        return $this->form($request, null);
    }

    public function store(Request $request, NumberingService $numbering): RedirectResponse
    {
        $data = $this->validated($request, null);
        $customer = DB::transaction(function () use ($data, $request, $numbering) {
            $data['code'] = $numbering->resolve('customer', $data['code'] ?? null, ['workplace_id' => $request->user()->workplace_id], $data['code_offered'] ?? null);
            unset($data['code_offered']);

            return Customer::query()->create($data + ['type' => 'customer', 'created_by' => $request->user()->id]);
        });

        return redirect()->route('customers.show', $customer)->with('popup', ['title' => __('Müştəri yaradıldı'), 'message' => null,
            'docs' => [['type' => __('Müştəri kartı'), 'no' => $customer->code, 'url' => route('customers.show', $customer)]]]);
    }

    public function edit(Request $request, Customer $customer): View
    {
        $this->authorizeCustomer($request, $customer);

        return $this->form($request, $customer);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        $data = $this->validated($request, $customer);
        unset($data['code_offered']);
        $customer->fill($data)->save();

        return redirect()->route('customers.show', $customer)->with('popup', ['title' => __('Müştəri kartı yeniləndi'), 'message' => $customer->code.' — '.$customer->name]);
    }

    private function form(Request $request, ?Customer $c): View
    {
        $restricted = $request->user()->restrictedManagerId();
        $numbering = app(NumberingService::class);

        return view('customers.form', [
            'title' => $c ? __('Müştəri kartı: düzəliş').' — '.$c->code : __('Yeni müştəri'),
            'customer' => $c,
            'managers' => DB::table('managers')->when($restricted !== null, fn ($q) => $q->where('id', $restricted))->orderBy('name')->get(['id', 'name']),
            'priceLists' => DB::table('price_lists')->where('type', 'sale')->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'regions' => DB::table('customers')->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'),
            'managerLocked' => $restricted !== null,
            'numbering' => ['mode' => $numbering->mode('customer'), 'preview' => $numbering->preview('customer')],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Customer $customer): array
    {
        $restricted = $request->user()->restrictedManagerId();
        $data = $request->validate([
            'code' => [Rule::requiredIf($customer !== null || app(NumberingService::class)->mode('customer') === 'manual'), 'nullable', 'string', 'max:64', Rule::unique('customers', 'code')->ignore($customer?->id)],
            'code_offered' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            // bir şirkətin bir neçə təhvil nöqtəsi eyni VÖEN-lə ola bilər — unikal deyil
            'voen' => ['nullable', 'regex:/^\d{10}$/'],
            'manager_id' => ['nullable', 'integer', 'exists:managers,id'],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where('type', 'sale')],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{5,32}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:64'],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'status' => ['required', Rule::in(['active', 'blocked'])],
            'block_reason' => ['nullable', 'required_if:status,blocked', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['voen.regex' => __('VÖEN 10 rəqəmdən ibarət olmalıdır.')], [
            'code' => __('Kod'), 'name' => __('Ad'), 'voen' => __('VÖEN'), 'phone' => __('Telefon'), 'payment_days' => __('Ödəniş müddəti (gün)'),
            'credit_limit' => __('Kredit limiti'), 'block_reason' => __('Bloklama səbəbi'),
        ]);
        // menecer: müştəri həmişə özünə bağlıdır, limit / bloklama yalnız admindədir
        if ($restricted !== null) {
            $data['manager_id'] = $restricted;
            foreach (['credit_limit', 'status', 'block_reason', 'payment_days'] as $key) {
                if ($customer) {
                    $data[$key] = $customer->{$key};
                } else {
                    unset($data[$key]);
                }
            }
        }
        if (($data['status'] ?? 'active') === 'active') {
            $data['block_reason'] = null;
        }

        return $data;
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        $restricted = $request->user()->restrictedManagerId();
        abort_if($restricted !== null && $customer->manager_id !== $restricted, 403);
    }
}
