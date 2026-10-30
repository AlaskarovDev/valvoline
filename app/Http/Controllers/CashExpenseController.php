<?php

namespace App\Http\Controllers;

use App\Models\FinanceDocument;
use App\Models\User;
use App\Models\Workplace;
use App\Services\CashExpenseService;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Kassa məxarici (xərc), təhtəlhesab avans və avans hesabatı.
 */
class CashExpenseController extends Controller
{
    public const TYPES = ['cash_expense', 'cash_advance', 'advance_report'];

    public function create(Request $request, CashExpenseService $expenses, FinanceService $finance): View
    {
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : 'cash_expense';
        $balances = $expenses->balances();

        return view('finance.expense-form', [
            'title' => FinanceDocument::typeLabels()[$type], 'type' => $type,
            'registers' => DB::table('cash_registers')->where('is_active', true)->orderByRaw("CASE WHEN type = 'main' THEN 0 ELSE 1 END")->orderBy('code')->get(['id', 'code', 'name']),
            'cash' => $finance->cashBalances(),
            'categories' => DB::table('expense_categories')->where('op_type', 'like', 'X%')->orderBy('name')->get(['id', 'name', 'affects_profit']),
            'employees' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'open' => $balances[$u->id]['open'] ?? 0.0])->values(),
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'defaultWorkplace' => Workplace::defaultIdFor($request->user()),
            'preset' => ['employee' => $request->integer('employee') ?: null],
        ]);
    }

    public function store(Request $request, CashExpenseService $expenses): RedirectResponse
    {
        $type = $request->input('type');
        abort_unless(in_array($type, self::TYPES, true), 422);
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
            'cash_register_id' => ['required', 'integer', 'exists:cash_registers,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'amount' => [$type === 'advance_report' ? 'nullable' : 'required', 'numeric', 'gt:0', 'max:100000000'],
            'expense_category_id' => [$type === 'cash_expense' ? 'required' : 'nullable', 'integer', 'exists:expense_categories,id'],
            'employee_id' => [$type === 'cash_expense' ? 'nullable' : 'required', 'integer', 'exists:users,id'],
            'returned' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.expense_category_id' => ['required_with:lines', 'integer', 'exists:expense_categories,id'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => __('Məbləğ'), 'expense_category_id' => __('Xərc maddəsi'), 'employee_id' => __('İşçi')]);
        try {
            $doc = match ($type) {
                'cash_expense' => $expenses->expense($data, $request->user()->id),
                'cash_advance' => $expenses->advance($data, $request->user()->id),
                default => $expenses->report($data, array_values($data['lines'] ?? []), $request->user()->id),
            };
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('finance.documents.show', $doc)->with('popup', ['title' => __('Sənəd yaradıldı'), 'message' => null,
            'docs' => [['type' => FinanceDocument::typeLabels()[$type], 'no' => $doc->doc_no, 'url' => route('finance.documents.show', $doc)]]]);
    }

    /**
     * Təhtəlhesab şəxslər: verilən avans, hesabat və açıq qalıq.
     */
    public function advances(CashExpenseService $expenses): View
    {
        $balances = $expenses->balances();
        $users = User::query()->whereIn('id', array_keys($balances))->orderBy('name')->get(['id', 'name']);

        return view('finance.advances', ['title' => __('Təhtəlhesab şəxslər'), 'rows' => $users->map(fn ($u) => (object) (['id' => $u->id, 'name' => $u->name] + $balances[$u->id]))]);
    }
}
