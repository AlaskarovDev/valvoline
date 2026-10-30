<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\Workplace;
use App\Services\FinanceService;
use App\Services\SupplierLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Təchizatçıya ödəniş (kassadan və ya bank hesabından): kreditor borcu azalır.
 * Hesabın valyutası təchizatçınınkından fərqlidirsə borcdan silinən məbləğ ayrıca yazılır.
 */
class SupplierPaymentController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function create(Request $request, SupplierLedger $ledger): View
    {
        $suppliers = Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'currency']);
        $balances = $ledger->balances();

        return view('suppliers.payment', [
            'title' => __('Təchizatçıya ödəniş'),
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'defaultWorkplace' => Workplace::defaultIdFor($request->user()),
            'config' => [
                'suppliers' => $suppliers->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'currency' => $s->currency, 'balance' => $balances[$s->id]['balance'] ?? 0.0])->values(),
                'registers' => DB::table('cash_registers')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'currency']),
                'accounts' => DB::table('bank_accounts')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'currency']),
                'supplier' => (int) old('supplier_id', $request->integer('supplier')) ?: null,
                'source' => old('source', 'bank'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'source' => ['required', Rule::in(['cash', 'bank'])],
            'cash_register_id' => ['nullable', 'required_if:source,cash', 'integer', 'exists:cash_registers,id'],
            'bank_account_id' => ['nullable', 'required_if:source,bank', 'integer', 'exists:bank_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'to_amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['supplier_id' => __('Təchizatçı'), 'cash_register_id' => __('Kassa'), 'bank_account_id' => __('Bank hesabı'), 'to_amount' => __('Borcdan silinən məbləğ')]);
        $supplierCurrency = Supplier::query()->whereKey($data['supplier_id'])->value('currency');
        if ($data['source'] === 'cash') {
            $data['bank_account_id'] = null;
            $data['currency'] = DB::table('cash_registers')->where('id', $data['cash_register_id'])->value('currency') ?? 'AZN';
        } else {
            $data['cash_register_id'] = null;
            $data['currency'] = DB::table('bank_accounts')->where('id', $data['bank_account_id'])->value('currency') ?? 'AZN';
        }
        if ($data['currency'] !== $supplierCurrency && empty($data['to_amount'])) {
            return back()->withInput()->withErrors(['to_amount' => __('Valyutalar fərqlidir (:a → :b): borcdan silinən məbləği yazın.', ['a' => $data['currency'], 'b' => $supplierCurrency])]);
        }
        $data['to_amount'] = $data['currency'] === $supplierCurrency ? $data['amount'] : $data['to_amount'];
        $data['rate'] = round($data['to_amount'] / $data['amount'], 6);
        try {
            if ($data['source'] === 'cash') {
                $this->finance->assertCashAvailable((int) $data['cash_register_id'], (float) $data['amount'], Carbon::parse($data['date']));
            }
            $doc = $this->finance->create('supplier_payment', $data, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('finance.documents.show', $doc)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => __('Təchizatçıya ödəniş'), 'no' => $doc->doc_no, 'url' => route('finance.documents.show', $doc)]]]);
    }
}
