<?php

namespace App\Services;

use App\Models\FinanceDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kassadan xərc və təhtəlhesab avans:
 *  - KX (kassa məxarici): kassadan çıxır, xərc maddəsi ilə Xərclər / mənfəət-zərərə düşür;
 *  - AV (avans): kassadan işçiyə verilir — hələ xərc deyil, işçinin hesabatı gözlənilir;
 *  - AH (avans hesabatı): işçinin xərcləri (maddələr üzrə) xərcə yazılır, istifadə olunmayan pul kassaya qaytarılır.
 */
class CashExpenseService
{
    public function __construct(private FinanceService $finance) {}

    /**
     * @param  array{date: string, workplace_id: int, cash_register_id: int, amount: float|string, expense_category_id: int, direction?: string|null, note?: string|null}  $data
     */
    public function expense(array $data, int $userId): FinanceDocument
    {
        $this->finance->assertCashAvailable((int) $data['cash_register_id'], (float) $data['amount'], Carbon::parse($data['date']));

        return DB::transaction(function () use ($data, $userId) {
            $doc = $this->finance->create('cash_expense', $data, $userId);
            $doc->forceFill(['expense_category_id' => $data['expense_category_id'], 'direction' => $data['direction'] ?? 'VALVO'])->save();
            $this->writeExpense($doc, (int) $data['expense_category_id'], (float) $data['amount'], $data['note'] ?? null);

            return $doc;
        });
    }

    /**
     * @param  array{date: string, workplace_id: int, cash_register_id: int, amount: float|string, employee_id: int, note?: string|null}  $data
     */
    public function advance(array $data, int $userId): FinanceDocument
    {
        $this->finance->assertCashAvailable((int) $data['cash_register_id'], (float) $data['amount'], Carbon::parse($data['date']));

        return DB::transaction(function () use ($data, $userId) {
            $doc = $this->finance->create('cash_advance', $data, $userId);
            $doc->forceFill(['employee_id' => $data['employee_id']])->save();

            return $doc;
        });
    }

    /**
     * @param  array{date: string, workplace_id: int, cash_register_id: int, employee_id: int, returned?: float|string|null, direction?: string|null, note?: string|null}  $data
     * @param  list<array{expense_category_id: int, amount: float|string, description?: string|null}>  $lines
     */
    public function report(array $data, array $lines, int $userId): FinanceDocument
    {
        $lines = array_values(array_filter($lines, fn ($l) => (float) $l['amount'] > 0));
        $spent = round(array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)), 2);
        $returned = round((float) ($data['returned'] ?? 0), 2);
        if ($spent + $returned <= 0) {
            throw new RuntimeException(__('Xərc və ya qaytarılan məbləğ yazın.'));
        }
        $open = $this->balances()[(int) $data['employee_id']]['open'] ?? 0.0;
        if ($spent + $returned > $open + 0.004) {
            throw new RuntimeException(__('Hesabat avans qalığından çoxdur: qalıq :open.', ['open' => number_format($open, 2, ',', ' ')]));
        }

        return DB::transaction(function () use ($data, $lines, $spent, $returned, $userId) {
            $doc = $this->finance->create('advance_report', ['amount' => $spent, 'to_amount' => $returned] + $data, $userId);
            $doc->forceFill(['employee_id' => $data['employee_id'], 'direction' => $data['direction'] ?? 'VALVO'])->save();
            foreach ($lines as $l) {
                $this->writeExpense($doc, (int) $l['expense_category_id'], (float) $l['amount'], $l['description'] ?? null);
            }

            return $doc;
        });
    }

    /**
     * İşçilər üzrə avans: verilən, hesabat verilən (xərc + qaytarılan) və açıq qalıq.
     *
     * @return array<int, array{given: float, reported: float, open: float}>
     */
    public function balances(): array
    {
        $out = [];
        foreach (DB::table('finance_documents')->whereNull('cancelled_at')->whereIn('type', ['cash_advance', 'advance_report'])->whereNotNull('employee_id')
            ->get(['employee_id', 'type', 'amount', 'to_amount']) as $d) {
            $row = &$out[(int) $d->employee_id];
            $row ??= ['given' => 0.0, 'reported' => 0.0, 'open' => 0.0];
            if ($d->type === 'cash_advance') {
                $row['given'] += (float) $d->amount;
            } else {
                $row['reported'] += (float) $d->amount + (float) $d->to_amount;
            }
            $row['open'] = round($row['given'] - $row['reported'], 2);
            unset($row);
        }

        return $out;
    }

    /**
     * Avans hesabatının xərc sətirləri.
     *
     * @return Collection<int, object>
     */
    public function lines(FinanceDocument $doc): Collection
    {
        return DB::table('cash_transactions')->where('finance_document_id', $doc->id)->orderBy('id')->get(['category_name', 'description', 'expense']);
    }

    private function writeExpense(FinanceDocument $doc, int $categoryId, float $amount, ?string $description): void
    {
        $category = DB::table('expense_categories')->find($categoryId);
        DB::table('cash_transactions')->insert([
            'row_no' => (int) DB::table('cash_transactions')->max('row_no') + 1, 'date' => $doc->date->toDateString(), 'period' => $doc->date->format('Y-m'),
            'expense_category_id' => $categoryId, 'category_name' => $category->name, 'description' => trim($doc->doc_no.' '.($description ?? '')),
            'income' => 0, 'expense' => round($amount, 4), 'op_type' => 'Xərc', 'affects_profit_raw' => (bool) $category->affects_profit,
            'affects_profit' => (bool) $category->affects_profit, 'direction' => $doc->direction ?? 'VALVO', 'category_source' => 'system',
            'finance_document_id' => $doc->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
