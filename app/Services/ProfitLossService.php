<?php

namespace App\Services;

use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Support\NameNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
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
 * Rəsmi xərclər       = mühasibat Cash Flow-un «Ödənişlər» bölməsi (mal alışı / gömrük xaric — onlar mayadadır).
 * Re-eksport          = mühasibat MZ-dəki AR hüdudlarından kənar gəlir və onunla bağlı xərc (satış faylında yoxdur).
 * Rəsmi əlavə gəlir   = bank daxilolması, ödəyicisi sistemdə olmayan və «sistemdə olmayan satış» seçilmiş (OfficialRevenueService).
 */
class ProfitLossService
{
    /**
     * @param  'month'|'year'  $granularity
     * @param  bool  $withOfficial  rəsmi (bank) xərclər daxil edilsinmi — P&L-in yuxarısındakı düymə
     * @return array{columns: list<string>, lines: array<string, array<string, float>>, expenses: array<string, array<string, float>>, other_income: array<string, array<string, float>>, founder: array<string, array<string, float>>}
     */
    public function build(ReportFilters $filters, string $granularity = 'month', bool $withOfficial = true): array
    {
        $sales = SalesSummaryQuery::base($filters)
            ->groupBy(DB::raw($granularity === 'year' ? 'SUBSTR(s.period, 1, 4)' : 's.period'))
            ->selectRaw(($granularity === 'year' ? 'SUBSTR(s.period, 1, 4)' : 's.period').' AS col, '.SalesSummaryQuery::METRICS)
            ->get()->keyBy('col');

        $expenses = $this->cashByCategory($filters, $granularity, 'expense');
        $otherIncome = $this->cashByCategory($filters, $granularity, 'income');
        $founder = $this->cashByCategory($filters, $granularity, 'founder');
        $bank = $withOfficial ? $this->bankExpensesByGroup($filters, $granularity) : [];
        $bankRows = $withOfficial ? $this->bankExpensesByCounterparty($filters, $granularity) : [];
        $export = $this->exportByColumn($filters, $granularity);
        $officialExtra = app(OfficialRevenueService::class)->extraByColumn($filters, $granularity);

        $columns = array_unique(array_merge(
            $sales->keys()->map(fn ($k) => (string) $k)->all(),
            array_merge(...array_map('array_keys', array_values($expenses) ?: [[]])),
            array_merge(...array_map('array_keys', array_values($otherIncome) ?: [[]])),
            array_merge(...array_map('array_keys', array_values($founder) ?: [[]])),
            array_merge(...array_map('array_keys', array_values($bank) ?: [[]])),
            array_keys($export['revenue']),
            array_keys($officialExtra),
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
            $lines['export_revenue'][$column] = $export['revenue'][$column] ?? 0.0;
            $lines['export_cost'][$column] = $export['cost'][$column] ?? 0.0;
            $lines['export_result'][$column] = $lines['export_revenue'][$column] - $lines['export_cost'][$column];
            $lines['official_extra'][$column] = $officialExtra[$column] ?? 0.0;
            $lines['net_profit'][$column] = $row->gross_profit + $incomeTotal - $expenseTotal - $bankTotal + $lines['export_result'][$column] + $lines['official_extra'][$column];
            $base = $row->net_sales + $lines['export_revenue'][$column] + $lines['official_extra'][$column];
            $lines['net_margin'][$column] = abs($base) > 0.005 ? $lines['net_profit'][$column] / $base : 0;
            $founderTotal = array_sum(array_map(fn ($c) => $c[$column] ?? 0, $founder));
            $lines['founder'][$column] = $founderTotal;
            $lines['retained'][$column] = $lines['net_profit'][$column] - $founderTotal;
        }

        return ['columns' => $columns, 'lines' => $lines, 'expenses' => $expenses, 'other_income' => $otherIncome, 'founder' => $founder, 'bank' => $bank, 'bank_rows' => $bankRows];
    }

    /**
     * Rəsmi xərclər: mühasibat Cash Flow-un «Ödənişlər» bölməsi, P&L qrupu üzrə (FlowClassifier::pnlGroup).
     * Mal alışı / gömrük çıxılır. Bank məlumatı «VALVOLINE AZ» MMC-yə aiddir — istiqamət VALVO və ya «hamısı» olanda.
     *
     * @param  'month'|'year'  $granularity
     * @return array<string, array<string, float>> qrup => sütun => məbləğ
     */
    public function bankExpensesByGroup(ReportFilters $filters, string $granularity = 'month'): array
    {
        $result = [];
        foreach ($this->bankPaymentLines($filters) as $row) {
            $column = $granularity === 'year' ? substr($row->period, 0, 4) : $row->period;
            $result[$row->pnl_group][$column] = ($result[$row->pnl_group][$column] ?? 0.0) + (float) $row->amount;
        }
        uasort($result, fn ($a, $b) => array_sum($b) <=> array_sum($a));

        return $result;
    }

    /**
     * Eyni məbləğ Excel-dəki kimi kontragent sətirləri üzrə (faylın sırası ilə).
     *
     * @param  'month'|'year'  $granularity
     * @return array<string, array<string, float>> kontragent => sütun => məbləğ
     */
    public function bankExpensesByCounterparty(ReportFilters $filters, string $granularity = 'month'): array
    {
        $result = [];
        foreach ($this->bankPaymentLines($filters) as $row) {
            $column = $granularity === 'year' ? substr($row->period, 0, 4) : $row->period;
            $result[$row->counterparty][$column] = ($result[$row->counterparty][$column] ?? 0.0) + (float) $row->amount;
        }

        return $result;
    }

    /**
     * P&L-ə düşən bank ödənişi sətirləri (təsnifat və P&L qrupu ilə).
     *
     * @return Collection<int, object>
     */
    public function bankPaymentLines(ReportFilters $filters): Collection
    {
        if ($filters->direction !== null && ! in_array($filters->direction, ['all', 'VALVO'], true)) {
            return collect();
        }
        $classifier = new FlowClassifier;
        $memo = [];

        return DB::table('bank_flow_lines')->where('section', 'payment')->where('amount', '!=', 0)
            ->whereBetween('period', [$filters->fromPeriod(), $filters->toPeriod()])
            ->orderBy('year')->orderBy('row_no')->orderBy('period')
            ->get(['year', 'period', 'counterparty', 'amount', 'row_no'])
            ->map(function ($row) use ($classifier, &$memo) {
                $c = $memo[$row->counterparty] ??= $classifier->classify($row->counterparty, 'payment');
                $row->type = $c['type'];
                $row->note = $c['note'];
                $row->pnl_group = FlowClassifier::pnlGroup($c);

                return $row;
            })
            ->filter(fn ($row) => $row->pnl_group !== null)->values();
    }

    public function bankExpenseTotal(ReportFilters $filters): float
    {
        return (float) array_sum(array_map('array_sum', $this->bankExpensesByGroup($filters)));
    }

    /**
     * Rəsmi re-eksport: MZ-dəki illik «AR hüdudlarından kənar» gəlir və onunla bağlı xərc.
     * Aylara xarici alıcılardan (OOO …) bank daxilolmalarının payı ilə bölünür — bölgü təxminidir, illik cəm dəqiqdir.
     *
     * @param  'month'|'year'  $granularity
     * @return array{revenue: array<string, float>, cost: array<string, float>}
     */
    public function exportByColumn(ReportFilters $filters, string $granularity = 'month'): array
    {
        $result = ['revenue' => [], 'cost' => []];
        if ($filters->direction !== null && ! in_array($filters->direction, ['all', 'VALVO'], true)) {
            return $result;
        }
        [$from, $to] = [$filters->fromPeriod(), $filters->toPeriod()];
        $lines = DB::table('acc_pl_lines')->whereBetween('year', [(int) substr($from, 0, 4), (int) substr($to, 0, 4)])
            ->whereIn('section', ['income', 'expense'])->get(['year', 'section', 'tax_code', 'name', 'amount']);
        foreach ($lines->filter(fn ($l) => self::isExportLine($l))->groupBy('year') as $year => $group) {
            $totals = ['revenue' => (float) $group->where('section', 'income')->sum('amount'), 'cost' => (float) $group->where('section', 'expense')->sum('amount')];
            foreach (self::exportWeights((int) $year) as $period => $weight) {
                if ($period < $from || $period > $to) {
                    continue;
                }
                $column = $granularity === 'year' ? substr($period, 0, 4) : $period;
                foreach ($totals as $key => $amount) {
                    $result[$key][$column] = ($result[$key][$column] ?? 0.0) + $amount * $weight;
                }
            }
        }

        return $result;
    }

    /**
     * İdarə paneli üçün P&L cəmləri (P&L səhifəsi ilə eyni hesablama).
     *
     * @return array{net_profit: float, expenses: float, other_income: float}
     */
    public function totals(ReportFilters $filters, bool $withOfficial = false): array
    {
        $lines = $this->build($filters, 'year', $withOfficial)['lines'];

        return [
            'net_profit' => (float) array_sum($lines['net_profit'] ?? []),
            'expenses' => (float) array_sum($lines['expenses'] ?? []) + (float) array_sum($lines['bank_expenses'] ?? []),
            'other_income' => (float) array_sum($lines['other_income'] ?? []) + (float) array_sum($lines['export_result'] ?? []) + (float) array_sum($lines['official_extra'] ?? []),
        ];
    }

    public function exportResultTotal(ReportFilters $filters): float
    {
        $export = $this->exportByColumn($filters);

        return (float) (array_sum($export['revenue']) - array_sum($export['cost']));
    }

    /**
     * MZ sətri AR hüdudlarından kənar (re-eksport) gəlir / xərcdirmi: 217.2 gəlir, 232.1 xərc.
     */
    public static function isExportLine(object $line): bool
    {
        return str_contains(NameNormalizer::normalize($line->name), 'hududlarindan kenar')
            || in_array($line->tax_code, ['217.2', '232.1'], true);
    }

    /**
     * Re-eksport məbləğinin aylara bölgüsü: xarici alıcılardan (adı «OOO» ilə başlayan) bank daxilolmaları;
     * belə daxilolma yoxdursa ilin aylarına bərabər.
     *
     * @return array<string, float> dövr => pay (cəmi 1)
     */
    public static function exportWeights(int $year): array
    {
        $receipts = DB::table('bank_flow_lines')->where('year', $year)->where('section', 'income')
            ->where('normalized', 'like', 'ooo %')->where('amount', '>', 0)
            ->groupBy('period')->selectRaw('period, SUM(amount) AS amount')->pluck('amount', 'period')->map(fn ($v) => (float) $v);
        $sum = $receipts->sum();
        if ($sum > 0) {
            return $receipts->map(fn ($v) => $v / $sum)->all();
        }
        $months = array_map(fn ($m) => sprintf('%d-%02d', $year, $m), range(1, 12));

        return array_fill_keys($months, 1 / 12);
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
