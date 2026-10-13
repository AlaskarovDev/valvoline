<?php

namespace App\Services;

use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Support\NameNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Mənfəət-zərər (P&L):
 * Satış → Qaytarma → Xalis satış → Maya → Brüt mənfəət → Xərclər (kateqoriya üzrə) → Digər gəlirlər → Xalis mənfəət
 * → Təsisçiyə ödənişlər → Bölüşdürülməmiş mənfəət.
 *
 * Təsisçiyə ödənişlər (Emeliyyat_Tipi = «Təsisçiyə ödəniş») xərc deyil — mənfəətin bölüşdürülməsidir.
 *
 * Əməliyyat xərcləri  = kassa: Emeliyyat_Tipi = Xərc  və effektiv Mənfəətə təsir = Bəli (istiqamət filtri ilə).
 * Digər gəlirlər      = kassa: Emeliyyat_Tipi = Mədaxil və effektiv Mənfəətə təsir = Bəli.
 */
class ProfitLossService
{
    /**
     * @param  'month'|'year'  $granularity
     * @return array{columns: list<string>, lines: array<string, array<string, float>>, expenses: array<string, array<string, float>>, other_income: array<string, array<string, float>>, founder: array<string, array<string, float>>}
     */
    public function build(ReportFilters $filters, string $granularity = 'month'): array
    {
        $sales = SalesSummaryQuery::base($filters)
            ->groupBy(DB::raw($granularity === 'year' ? 'SUBSTR(s.period, 1, 4)' : 's.period'))
            ->selectRaw(($granularity === 'year' ? 'SUBSTR(s.period, 1, 4)' : 's.period').' AS col, '.SalesSummaryQuery::METRICS)
            ->get()->keyBy('col');

        $expenses = $this->cashByCategory($filters, $granularity, 'expense');
        $otherIncome = $this->cashByCategory($filters, $granularity, 'income');
        $founder = $this->cashByCategory($filters, $granularity, 'founder');
        $bank = $this->bankExpensesByGroup($filters, $granularity);

        $columns = array_unique(array_merge(
            $sales->keys()->map(fn ($k) => (string) $k)->all(),
            array_merge(...array_map('array_keys', array_values($expenses) ?: [[]])),
            array_merge(...array_map('array_keys', array_values($otherIncome) ?: [[]])),
            array_merge(...array_map('array_keys', array_values($founder) ?: [[]])),
            array_merge(...array_map('array_keys', array_values($bank) ?: [[]])),
        ));
        sort($columns);

        $lines = [];
        foreach ($columns as $column) {
            $row = SalesSummaryQuery::derive($sales[$column] ?? (object) []);
            $expenseTotal = array_sum(array_map(fn ($c) => $c[$column] ?? 0, $expenses));
            $bankTotal = array_sum(array_map(fn ($c) => $c[$column] ?? 0, $bank));
            $incomeTotal = array_sum(array_map(fn ($c) => $c[$column] ?? 0, $otherIncome));
            $lines['sales'][$column] = $row->sales;
            $lines['returns'][$column] = $row->returns;
            $lines['net_sales'][$column] = $row->net_sales;
            $lines['sale_cogs'][$column] = (float) ($row->sale_cogs ?? 0) - (float) ($row->return_cogs ?? 0);
            $lines['gift_cogs'][$column] = (float) ($row->gift_cogs ?? 0);
            $lines['cogs'][$column] = $row->cogs;
            $lines['gross_profit'][$column] = $row->gross_profit;
            $lines['margin'][$column] = $row->margin ?? 0;
            $lines['expenses'][$column] = $expenseTotal;
            $lines['other_income'][$column] = $incomeTotal;
            $lines['bank_expenses'][$column] = $bankTotal;
            $lines['net_profit'][$column] = $row->gross_profit + $incomeTotal - $expenseTotal - $bankTotal;
            $lines['net_margin'][$column] = abs($row->net_sales) > 0.005 ? $lines['net_profit'][$column] / $row->net_sales : 0;
            $founderTotal = array_sum(array_map(fn ($c) => $c[$column] ?? 0, $founder));
            $lines['founder'][$column] = $founderTotal;
            $lines['retained'][$column] = $lines['net_profit'][$column] - $founderTotal;
        }

        return ['columns' => $columns, 'lines' => $lines, 'expenses' => $expenses, 'other_income' => $otherIncome, 'founder' => $founder, 'bank' => $bank];
    }

    /**
     * Mühasibat Cash Flow-dan (bank) yalnız «əməliyyat xərci» təsnifatlı ödənişlər, xərc qrupu üzrə.
     * Bank məlumatı «VALVOLINE AZ» MMC-yə aiddir — istiqamət filtri VALVO və ya «hamısı» olanda daxil edilir.
     *
     * @param  'month'|'year'  $granularity
     * @return array<string, array<string, float>> qrup => sütun => məbləğ
     */
    public function bankExpensesByGroup(ReportFilters $filters, string $granularity = 'month'): array
    {
        if ($filters->direction !== null && ! in_array($filters->direction, ['all', 'VALVO'], true)) {
            return [];
        }
        $rows = DB::table('bank_flow_lines')->where('section', 'payment')
            ->whereBetween('period', [$filters->fromPeriod(), $filters->toPeriod()])
            ->get(['period', 'counterparty', 'amount']);
        $classifier = new FlowClassifier;
        $memo = [];
        $result = [];
        foreach ($rows as $row) {
            $c = $memo[$row->counterparty] ??= $classifier->classify($row->counterparty, 'payment');
            if ($c['type'] !== FlowClassifier::EXPENSE) {
                continue;
            }
            $group = $c['group'] ?: 'Sair xərclər';
            $column = $granularity === 'year' ? substr($row->period, 0, 4) : $row->period;
            $result[$group][$column] = ($result[$group][$column] ?? 0.0) + (float) $row->amount;
        }
        uasort($result, fn ($a, $b) => array_sum($b) <=> array_sum($a));

        return $result;
    }

    public function bankExpenseTotal(ReportFilters $filters): float
    {
        return (float) array_sum(array_map('array_sum', $this->bankExpensesByGroup($filters)));
    }

    /**
     * @param  'month'|'year'  $granularity
     * @param  'expense'|'income'|'founder'  $kind
     * @return array<string, array<string, float>> kateqoriya => sütun => məbləğ
     */
    public function cashByCategory(ReportFilters $filters, string $granularity, string $kind): array
    {
        $column = $granularity === 'year' ? 'SUBSTR(c.period, 1, 4)' : 'c.period';
        $amount = $kind === 'income' ? 'SUM(c.income - c.expense)' : 'SUM(c.expense - c.income)';
        $rows = self::profitCash($filters, $kind)
            ->groupBy(DB::raw($column), 'c.category_name')
            ->selectRaw("{$column} AS col, c.category_name AS category, {$amount} AS amount")
            ->get();
        $result = [];
        foreach ($rows as $row) {
            $result[$row->category ?? '—'][(string) $row->col] = (float) $row->amount;
        }
        uasort($result, fn ($a, $b) => array_sum($b) <=> array_sum($a));

        return $result;
    }

    /**
     * Mənfəətə təsir edən kassa əməliyyatları (xərc və ya digər gəlir) və ya təsisçiyə ödənişlər.
     *
     * @param  'expense'|'income'|'founder'  $kind
     */
    public static function profitCash(ReportFilters $filters, string $kind): Builder
    {
        $query = DB::table('cash_transactions as c')
            ->whereBetween('c.date', [$filters->fromDate(), $filters->toDate()]);
        if ($kind !== 'founder') {
            $query->where('c.affects_profit', true);
        }
        $query->whereIn('c.op_type', self::opTypes()[$kind]);
        if ($filters->direction !== null && $filters->direction !== 'all') {
            $query->where('c.direction', $filters->direction);
        }

        return $query;
    }

    /**
     * Fayldakı Emeliyyat_Tipi yazılışlarını «xərc» və «mədaxil» qruplarına bölür.
     *
     * @return array{expense: list<string>, income: list<string>, founder: list<string>}
     */
    public static function opTypes(): array
    {
        $result = ['expense' => ['__none__'], 'income' => ['__none__'], 'founder' => ['__none__']];
        foreach (DB::table('cash_transactions')->whereNotNull('op_type')->distinct()->pluck('op_type') as $type) {
            $key = NameNormalizer::normalize($type);
            if (str_starts_with($key, 'tesisci') || str_starts_with($key, 'dividend')) {
                $result['founder'][] = $type;
            } elseif (str_starts_with($key, 'xerc')) {
                $result['expense'][] = $type;
            } elseif (str_starts_with($key, 'medaxil') || str_starts_with($key, 'gelir')) {
                $result['income'][] = $type;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function directions(): array
    {
        return DB::table('cash_transactions')->whereNotNull('direction')->distinct()->orderBy('direction')->pluck('direction')->all();
    }
}
