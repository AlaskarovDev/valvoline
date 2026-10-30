<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Jobs\RebuildAnalytics;
use App\Models\FinanceDocument;
use App\Models\Workplace;
use App\Reports\Table;
use App\Services\AnalyticsRebuilder;
use App\Services\FinanceService;
use App\Services\NumberingService;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maliyyə: kassalar (kodla, satış nümayəndəsinə bağlı), banklar və hesablar, maliyyə sənədləri.
 */
class FinanceController extends ReportController
{
    public function __construct(private FinanceService $finance) {}

    public function registers(Request $request): View
    {
        $balances = $this->finance->cashBalances();
        $registers = DB::table('cash_registers as r')->leftJoin('managers as m', 'm.id', '=', 'r.manager_id')->leftJoin('workplaces as w', 'w.id', '=', 'r.workplace_id')
            ->orderByRaw("CASE WHEN r.type = 'main' THEN 0 ELSE 1 END")->orderBy('r.code')
            ->get(['r.*', 'm.name as manager_name', 'w.name as workplace_name'])
            ->map(fn ($r) => tap($r, fn ($x) => $x->balance = $balances[$r->id] ?? 0.0));

        return view('finance.registers', [
            'title' => __('Kassalar'), 'registers' => $registers,
            'managers' => DB::table('managers')->whereNotIn('id', DB::table('cash_registers')->whereNotNull('manager_id')->pluck('manager_id'))->orderBy('name')->get(['id', 'name']),
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeRegister(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:cash_registers,name'],
            'code' => ['nullable', 'string', 'max:16', 'unique:cash_registers,code'],
            'manager_id' => ['nullable', 'integer', 'exists:managers,id', 'unique:cash_registers,manager_id'],
            'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
            'currency' => ['required', Rule::in(['AZN', 'USD', 'EUR', 'RUB'])],
        ]);
        $data['code'] ??= app(NumberingService::class)->next('cash_register', ['workplace_id' => $data['workplace_id'], 'manager_id' => $data['manager_id'] ?? null]);
        DB::table('cash_registers')->insert($data + ['type' => 'rep', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', __('«:code» kassası yaradıldı.', ['code' => $data['code']]));
    }

    public function register(Request $request, int $register): View
    {
        $r = DB::table('cash_registers as r')->leftJoin('managers as m', 'm.id', '=', 'r.manager_id')->leftJoin('workplaces as w', 'w.id', '=', 'r.workplace_id')
            ->where('r.id', $register)->first(['r.*', 'm.name as manager_name', 'w.name as workplace_name']) ?? abort(404);
        $ledger = $this->finance->cashLedger($register);

        return view('finance.register', ['title' => $r->code.' — '.$r->name, 'register' => $r, 'ledger' => $ledger->reverse()->values(), 'balance' => (float) optional($ledger->last())->balance]);
    }

    public function documents(Request $request): View|Response
    {
        $filters = $this->filters($request, 'last_12');
        $types = FinanceDocument::typeLabels();
        $type = array_key_exists((string) $request->query('type'), $types) ? $request->query('type') : null;
        $query = $this->applyState($request, DB::table('finance_documents as d'), 'd')->join('workplaces as w', 'w.id', '=', 'd.workplace_id')
            ->leftJoin('cash_registers as r1', 'r1.id', '=', 'd.cash_register_id')->leftJoin('cash_registers as r2', 'r2.id', '=', 'd.to_cash_register_id')
            ->leftJoin('bank_accounts as b1', 'b1.id', '=', 'd.bank_account_id')->leftJoin('bank_accounts as b2', 'b2.id', '=', 'd.to_bank_account_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->whereBetween('d.date', [$filters->fromDate(), $filters->toDate()])
            ->when($type, fn ($q) => $q->where('d.type', $type))
            ->when(is_numeric($request->query('register')), fn ($q) => $q->where(fn ($w) => $w->where('d.cash_register_id', (int) $request->query('register'))->orWhere('d.to_cash_register_id', (int) $request->query('register'))))
            ->select('d.id', 'd.doc_no', 'd.type', 'd.date', 'd.amount', 'd.to_amount', 'd.currency', 'd.note', 'w.name as workplace_name',
                DB::raw('COALESCE(r1.code, b1.name) AS source_name'), DB::raw('COALESCE(r2.code, b2.name, c.name) AS target_name'));
        $table = Table::make('findocs', __('Maliyyə sənədləri'))
            ->column('date', __('Tarix'), 'date', ['sql' => 'd.date'])
            ->column('doc_no', __('Sənəd №'), 'text', ['sql' => 'd.doc_no', 'link' => fn ($r) => route('finance.documents.show', $r->id)])
            ->column('type', __('Sənəd növü'), 'text', ['sql' => 'd.type', 'format' => fn ($v) => e($types[$v] ?? $v)])
            ->column('workplace_name', __('İş yeri'), 'text', ['sql' => 'w.name'])
            ->column('source_name', __('Kassa / hesab'), 'text', ['sortable' => false])
            ->column('target_name', __('Qarşı tərəf'), 'text', ['sortable' => false])
            ->column('amount', __('Məbləğ'), 'money', ['sql' => 'd.amount', 'format' => fn ($v, $r) => e(Fmt::number($v, 2).' '.$r->currency)])
            ->column('note', __('Qeyd'), 'text', ['sql' => 'd.note', 'mobile' => false])
            ->source($query)->sortBy('date', 'desc');
        if ($response = $this->exportIfRequested($request, [$table], __('Maliyyə sənədləri'), $filters)) {
            return $response;
        }

        return view('finance.documents', ['title' => __('Maliyyə sənədləri'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request), 'types' => $types, 'type' => $type]);
    }

    public function createDocument(Request $request): View
    {
        $types = FinanceDocument::typeLabels();
        $type = array_key_exists((string) $request->query('type'), $types) ? $request->query('type') : 'cash_receipt';
        $user = $request->user();
        $restricted = $user->restrictedManagerId();
        $registers = DB::table('cash_registers as r')->leftJoin('managers as m', 'm.id', '=', 'r.manager_id')->where('r.is_active', true)
            ->orderByRaw("CASE WHEN r.type = 'main' THEN 0 ELSE 1 END")->orderBy('r.code')->get(['r.id', 'r.code', 'r.name', 'r.manager_id', 'r.currency', 'm.name as manager_name']);

        return view('finance.document-form', [
            'title' => $types[$type], 'type' => $type, 'types' => $types, 'registers' => $registers,
            'accounts' => DB::table('bank_accounts as a')->leftJoin('banks as b', 'b.id', '=', 'a.bank_id')->where('a.is_active', true)->orderBy('b.name')->orderBy('a.name')
                ->get(['a.id', 'a.name', 'a.currency', 'a.iban', 'b.name as bank_name']),
            'customers' => DB::table('customers')->when($restricted !== null, fn ($q) => $q->where('manager_id', $restricted))->orderBy('name')->get(['id', 'code', 'name', 'manager_id'])
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'manager_id' => $c->manager_id])->values(),
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'defaultWorkplace' => Workplace::defaultIdFor($user),
            'balances' => $this->finance->cashBalances(),
            'preset' => ['register' => $request->query('register'), 'account' => $request->query('account'), 'customer' => $request->query('customer')],
        ]);
    }

    public function storeDocument(Request $request): RedirectResponse
    {
        $type = $request->input('type');
        abort_unless(array_key_exists((string) $type, FinanceDocument::typeLabels()), 422);
        abort_if(in_array($type, ['cash_opening', 'bank_opening', 'bank_transfer'], true) && ! $request->user()->isAdmin(), 403);
        $rules = [
            'type' => ['required'], 'date' => ['required', 'date'], 'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'amount' => [$type === 'cash_opening' || $type === 'bank_opening' ? 'required' : 'required', 'numeric', $type === 'cash_opening' || $type === 'bank_opening' ? 'min:0' : 'gt:0', 'max:1000000000'],
        ];
        $rules += match ($type) {
            'cash_opening' => ['cash_register_id' => ['required', 'integer', 'exists:cash_registers,id']],
            'cash_receipt' => ['cash_register_id' => ['required', 'integer', 'exists:cash_registers,id'], 'customer_id' => ['required', 'integer', 'exists:customers,id']],
            'cash_transfer' => ['cash_register_id' => ['required', 'integer', 'exists:cash_registers,id'], 'to_cash_register_id' => ['required', 'integer', 'exists:cash_registers,id', 'different:cash_register_id']],
            'bank_opening' => ['bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id']],
            'bank_transfer' => ['bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'], 'to_bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id', 'different:bank_account_id'],
                'to_amount' => ['nullable', 'numeric', 'gt:0']],
        };
        $data = $request->validate($rules, [], ['to_cash_register_id' => __('Qəbul edən kassa'), 'to_bank_account_id' => __('Qəbul edən hesab'), 'customer_id' => __('Müştəri')]);
        $date = Carbon::parse($data['date']);

        if ($type === 'cash_receipt') {
            $customer = DB::table('customers')->find($data['customer_id']);
            $restricted = $request->user()->restrictedManagerId();
            abort_if($restricted !== null && (int) $customer->manager_id !== $restricted, 403);
            $data['manager_id'] = DB::table('cash_registers')->where('id', $data['cash_register_id'])->value('manager_id') ?? $customer->manager_id;
        }
        if (in_array($type, ['cash_opening', 'cash_receipt', 'cash_transfer'], true)) {
            $data['currency'] = DB::table('cash_registers')->where('id', $data['cash_register_id'])->value('currency');
        }
        if (in_array($type, ['bank_opening', 'bank_transfer'], true)) {
            $data['currency'] = DB::table('bank_accounts')->where('id', $data['bank_account_id'])->value('currency');
        }
        if ($type === 'bank_transfer') {
            $toCurrency = DB::table('bank_accounts')->where('id', $data['to_bank_account_id'])->value('currency');
            if ($toCurrency !== $data['currency'] && empty($data['to_amount'])) {
                return back()->withInput()->withErrors(['to_amount' => __('Valyutalar fərqlidir (:a → :b): qəbul edilən məbləği yazın.', ['a' => $data['currency'], 'b' => $toCurrency])]);
            }
            $data['to_amount'] = $toCurrency === $data['currency'] ? $data['amount'] : $data['to_amount'];
            $data['rate'] = round($data['to_amount'] / $data['amount'], 6);
        }
        try {
            if ($type === 'cash_transfer') {
                $this->finance->assertCashAvailable((int) $data['cash_register_id'], (float) $data['amount'], $date);
                $data['to_amount'] = $data['amount'];
            }
            $doc = $this->finance->create($type, $data, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }
        if ($type === 'cash_receipt') {
            AnalyticsRebuilder::markQueued();
            RebuildAnalytics::dispatchAfterResponse();
        }

        return redirect()->route('finance.documents.show', $doc)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => FinanceDocument::typeLabels()[$type], 'no' => $doc->doc_no, 'url' => route('finance.documents.show', $doc)]]]);
    }

    public function showDocument(Request $request, FinanceDocument $document): View
    {
        $document->load('workplace', 'register', 'toRegister', 'account.bankRef', 'toAccount.bankRef', 'customer', 'manager', 'creator', 'canceller');

        return view($request->boolean('print') ? 'finance.document-print' : 'finance.document', [
            'title' => FinanceDocument::typeLabels()[$document->type].' № '.$document->doc_no, 'doc' => $document, 'types' => FinanceDocument::typeLabels(),
        ]);
    }

    public function destroyDocument(Request $request, FinanceDocument $document): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $no = $document->doc_no;
        $type = FinanceDocument::typeLabels()[$document->type];
        $this->finance->cancel($document, $request->user()->id, $reason);
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        return redirect()->route('finance.documents.show', $document)->with('popup', ['title' => __('Sənəd ləğv edildi'), 'message' => __(':type № :no ləğv edildi.', ['type' => $type, 'no' => $no]), 'docs' => []]);
    }

    public function banks(Request $request): View
    {
        $accounts = DB::table('bank_accounts')->orderBy('name')->get()->map(function ($a) {
            $b = $this->finance->bankBalance((int) $a->id);
            $a->balance = $b['balance'];
            $a->opening = $b['opening'];
            $a->last_date = $b['last_date'];

            return $a;
        });

        return view('finance.banks', [
            'title' => __('Banklar'),
            'banks' => DB::table('banks')->orderBy('name')->get(),
            'accounts' => $accounts->groupBy(fn ($a) => $a->bank_id ?? 0),
            'totals' => $accounts->where('is_active', true)->groupBy('currency')->map(fn ($g) => $g->sum('balance')),
        ]);
    }

    public function storeBank(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:banks,name'], 'code' => ['nullable', 'string', 'max:32'],
            'swift' => ['nullable', 'string', 'max:16'], 'voen' => ['nullable', 'string', 'max:16'], 'correspondent_account' => ['nullable', 'string', 'max:64'],
        ]);
        DB::table('banks')->insert($data + ['created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', __('«:name» bankı əlavə olundu.', ['name' => $data['name']]));
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'bank_id' => ['required', 'integer', 'exists:banks,id'],
            'currency' => ['required', Rule::in(['AZN', 'USD', 'EUR', 'RUB', 'TRY', 'GBP'])],
            'iban' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $bank = DB::table('banks')->where('id', $data['bank_id'])->value('name');
        $name = ($data['name'] ?? null) ?: $bank.' '.$data['currency'];
        if (DB::table('bank_accounts')->where('name', $name)->exists()) {
            return back()->withErrors(['name' => __('«:name» adlı hesab artıq var.', ['name' => $name])]);
        }
        DB::table('bank_accounts')->insert(['name' => $name, 'bank' => $bank, 'bank_id' => $data['bank_id'], 'currency' => $data['currency'], 'iban' => $data['iban'] ?? null,
            'kind' => 'bank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', __('«:name» hesabı açıldı.', ['name' => $name]));
    }
}
