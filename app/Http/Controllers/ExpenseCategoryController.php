<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Xərc kateqoriyaları kataloqu. «Mənfəətə təsir» bayrağını dəyişmək hesabatları dərhal dəyişir
 * (kateqoriyanın bütün kassa sətirlərinin effektiv bayrağı yenilənir; yenidən hesablama lazım deyil).
 */
class ExpenseCategoryController extends Controller
{
    public function index(): View
    {
        $categories = ExpenseCategory::query()
            ->leftJoin('cash_transactions as c', 'c.expense_category_id', '=', 'expense_categories.id')
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.op_type', 'expense_categories.affects_profit',
                'expense_categories.user_edited', 'expense_categories.description', 'expense_categories.row_count')
            ->selectRaw('expense_categories.id, expense_categories.name, expense_categories.op_type, expense_categories.affects_profit,
                expense_categories.user_edited, expense_categories.description, expense_categories.row_count,
                COUNT(c.id) AS rows_count, SUM(c.income) AS income, SUM(c.expense) AS expense')
            ->orderBy('expense_categories.op_type')->orderBy('expense_categories.name')->get();

        return view('categories.index', ['title' => 'Xərc kateqoriyaları', 'categories' => $categories]);
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'affects_profit' => ['required', 'boolean'],
            'op_type' => ['required', 'string', 'max:32'],
        ]);
        DB::transaction(function () use ($category, $data) {
            $category->fill($data + ['user_edited' => true])->save();
            DB::table('cash_transactions')->where('expense_category_id', $category->id)->update([
                'affects_profit' => $data['affects_profit'],
                'op_type' => $data['op_type'],
            ]);
        });

        return back()->with('status', "«{$category->name}» yeniləndi — P&L və xərc hesabatları dərhal dəyişdi.");
    }
}
