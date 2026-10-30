<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Models\Supplier;
use App\Reports\Table;
use App\Services\CurrencyRates;
use App\Services\NumberingService;
use App\Services\SupplierLedger;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alış → Təchizatçılar: kart, kreditor borcu, üzləşmə.
 */
class SupplierController extends ReportController
{
    public function index(Request $request, SupplierLedger $ledger): View|Response
    {
        $filters = $this->filters($request, 'all');
        $balances = $ledger->balances();
        $rows = Supplier::query()->orderBy('name')->get()->map(fn ($s) => (object) [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'voen' => $s->voen, 'country' => $s->country, 'currency' => $s->currency,
            'payment_days' => $s->payment_days, 'is_active' => $s->is_active,
            'received' => $balances[$s->id]['received'] ?? 0.0, 'paid' => $balances[$s->id]['paid'] ?? 0.0,
            'balance' => $balances[$s->id]['balance'] ?? 0.0, 'overdue' => $balances[$s->id]['overdue'] ?? 0.0,
        ])->when($request->query('only') === 'debt', fn ($c) => $c->filter(fn ($r) => $r->balance > 0.005))->values();

        $table = Table::make('suppliers', __('Təchizatçılar'))
            ->column('code', __('Kod'), 'text', ['link' => fn ($r) => route('suppliers.show', $r->id)])
            ->column('name', __('Təchizatçı'), 'text', ['link' => fn ($r) => route('suppliers.show', $r->id),
                'format' => fn ($v, $r) => e($v).($r->is_active ? '' : ' <span class="badge">'.e(__('passiv')).'</span>')])
            ->column('voen', __('VÖEN'), 'text', ['mobile' => false])
            ->column('country', __('Ölkə'), 'text', ['mobile' => false])
            ->column('currency', __('Valyuta'), 'text')
            ->column('received', __('Mədaxil'), 'text', ['format' => fn ($v, $r) => e(Fmt::number($v, 2).' '.$r->currency), 'mobile' => false])
            ->column('paid', __('Ödənilib'), 'text', ['format' => fn ($v, $r) => e(Fmt::number($v, 2).' '.$r->currency), 'mobile' => false])
            ->column('balance', __('Borcumuz'), 'text', ['format' => fn ($v, $r) => e(Fmt::number($v, 2).' '.$r->currency), 'class' => fn ($v) => $v > 0.005 ? 'warn font-semibold' : ''])
            ->column('overdue', __('Gecikmiş'), 'text', ['format' => fn ($v, $r) => $v > 0.005 ? e(Fmt::number($v, 2).' '.$r->currency) : '—', 'class' => fn ($v) => $v > 0.005 ? 'neg font-semibold' : ''])
            ->source($rows)->sortBy('name', 'asc')
            ->subtitle(__('Borcumuz təchizatçının valyutasındadır: mədaxil − qaytarma − ödəniş.'));
        if ($response = $this->exportIfRequested($request, [$table], __('Təchizatçılar'), $filters)) {
            return $response;
        }

        return view('suppliers.index', ['title' => __('Təchizatçılar'), 'table' => $table, 'rows' => $table->resolve($request)]);
    }

    public function show(Request $request, Supplier $supplier, SupplierLedger $ledger): View
    {
        $statement = $ledger->statement($supplier->id);
        $table = Table::make('supplier-akt', __('Üzləşmə'))
            ->column('date', __('Tarix'), 'date', ['sortable' => false])
            ->column('type', __('Əməliyyat'), 'text', ['sortable' => false])
            ->column('doc', __('Sənəd'), 'text', ['sortable' => false, 'link' => fn ($r) => $r['url']])
            ->column('credit', __('Borc artdı (mədaxil)'), 'money', ['sortable' => false])
            ->column('debit', __('Borc azaldı'), 'money', ['sortable' => false])
            ->column('balance', __('Qalıq'), 'money', ['sortable' => false, 'class' => fn ($v) => $v > 0.005 ? 'warn font-semibold' : 'font-semibold'])
            ->source(collect($statement))->perPage(100)->withoutSearch()
            ->subtitle(__('Məbləğlər :cur ilə', ['cur' => $supplier->currency]));

        return view('suppliers.show', ['title' => $supplier->name, 'supplier' => $supplier, 'balance' => $ledger->balances([$supplier->id])[$supplier->id] ?? null, 'fx' => $ledger->fx($supplier),
            'table' => $table, 'rows' => $table->resolve($request)]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function store(Request $request, NumberingService $numbering): RedirectResponse
    {
        $data = $this->validated($request, null);
        $data['code'] = $numbering->resolve('supplier', $data['code'] ?? null, ['workplace_id' => $request->user()->workplace_id], $data['code_offered'] ?? null);
        unset($data['code_offered']);
        $supplier = Supplier::query()->create($data + ['created_by' => $request->user()->id]);

        return redirect()->route('suppliers.show', $supplier)->with('popup', ['title' => __('Təchizatçı yaradıldı'), 'message' => null,
            'docs' => [['type' => __('Təchizatçı kartı'), 'no' => $supplier->code, 'url' => route('suppliers.show', $supplier)]]]);
    }

    public function edit(Supplier $supplier): View
    {
        return $this->form($supplier);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->validated($request, $supplier);
        unset($data['code_offered']);
        // sənədi olan təchizatçının valyutası dəyişmir — borc o valyutadadır
        if ($data['currency'] !== $supplier->currency && DB::table('purchase_documents')->where('supplier_id', $supplier->id)->exists()) {
            return back()->withInput()->withErrors(['currency' => __('Sənədi olan təchizatçının valyutası dəyişdirilmir.')]);
        }
        $supplier->fill($data)->save();

        return redirect()->route('suppliers.show', $supplier)->with('popup', ['title' => __('Təchizatçı kartı yeniləndi'), 'message' => $supplier->code.' — '.$supplier->name]);
    }

    private function form(?Supplier $s): View
    {
        $numbering = app(NumberingService::class);

        return view('suppliers.form', [
            'title' => $s ? __('Təchizatçı: düzəliş').' — '.$s->code : __('Yeni təchizatçı'), 'supplier' => $s, 'currencies' => CurrencyRates::codes(),
            'numbering' => ['mode' => $numbering->mode('supplier'), 'preview' => $numbering->preview('supplier')],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier): array
    {
        $data = $request->validate([
            'code' => [Rule::requiredIf($supplier !== null || app(NumberingService::class)->mode('supplier') === 'manual'), 'nullable', 'string', 'max:64', Rule::unique('suppliers', 'code')->ignore($supplier?->id)],
            'code_offered' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'voen' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:64'],
            'currency' => ['required', Rule::in(CurrencyRates::codes())],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => __('Ad'), 'currency' => __('Valyuta')]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
