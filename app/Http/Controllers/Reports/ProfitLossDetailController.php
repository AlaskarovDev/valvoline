<?php

namespace App\Http\Controllers\Reports;

use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Services\FlowClassifier;
use App\Services\OfficialRevenueService;
use App\Services\ProfitLossService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * P&L xanasının (sətir × ay/il və ya cəmi) necə hesablandığı: düstur, komponentlər və mənbə məlumatları.
 * Rəqəmlər P&L səhifəsi ilə eyni servisdən (ProfitLossService) həmin xananın dövrü üçün yenidən hesablanır.
 */
class ProfitLossDetailController extends ReportController
{
    public const LINES = ['sales', 'returns', 'net_sales', 'sale_cogs', 'gift_cogs', 'gross_profit', 'gross_margin', 'expenses', 'bank',
        'other_income', 'export', 'official_extra', 'net_profit', 'net_margin', 'founder', 'retained'];

    public function __invoke(Request $request, ProfitLossService $pnl): View
    {
        $filters = $this->filters($request, 'this_year', 'VALVO');
        $granularity = $request->query('gran') === 'year' ? 'year' : 'month';
        $line = in_array($request->query('line'), self::LINES, true) ? (string) $request->query('line') : 'net_profit';
        $col = preg_match('/^\d{4}(-\d{2})?$/', (string) $request->query('col')) ? (string) $request->query('col') : 'total';
        $child = $request->filled('child') ? (string) $request->query('child') : null;

        $cell = $this->cellFilters($filters, $col, $granularity);
        $report = $pnl->build($cell, 'year');
        $sum = fn (string $key) => (float) array_sum($report['lines'][$key] ?? []);
        $set = fn (string $key) => array_map(fn ($values) => (float) array_sum($values), $report[$key] ?? []);
        $totals = SalesSummaryQuery::totals($cell);

        [$label, $formula, $components, $result, $type] = $this->explain($line, $sum, $set, $totals, $child);
        $records = $this->records($line, $cell, $child, $set);

        return view($request->header('X-Modal') ? 'reports.pnl-detail' : 'reports.pnl-detail-page', [
            'title' => $label.' · '.$this->periodLabel($col, $filters),
            'label' => $label,
            'period' => $this->periodLabel($col, $filters),
            'direction' => $filters->direction && $filters->direction !== 'all' ? $filters->direction : __('bütün istiqamətlər'),
            'formula' => $formula,
            'components' => $components,
            'result' => $result,
            'type' => $type,
            'records' => $records,
            'line' => $line,
            'childLinks' => $child === null && in_array($line, ['expenses', 'bank', 'other_income', 'founder'], true)
                ? fn (string $name) => route('pnl.detail', $request->except('child') + ['child' => $name])
                : null,
        ]);
    }

    /**
     * Sətrin düsturu və həmin xananın komponentləri.
     *
     * @return array{0: string, 1: string, 2: list<array{label: string, value: float, sign: string, name?: string}>, 3: float, 4: string}
     */
    private function explain(string $line, callable $sum, callable $set, object $totals, ?string $child): array
    {
        $grossProfit = $sum('gross_profit');
        $netSales = $sum('net_sales');
        $marginBase = $netSales + $sum('export_revenue') + $sum('official_extra');
        $c = fn (string $label, float $value, string $sign = '', ?string $name = null) => array_filter(['label' => $label, 'value' => $value, 'sign' => $sign, 'name' => $name], fn ($v) => $v !== null);
        $categories = fn (array $values, string $sign, bool $groups = false) => array_values(array_map(
            fn ($name, $value) => $c($groups ? FlowClassifier::groupLabel($name) : (string) $name, $value, $sign, (string) $name),
            array_keys($values), array_values($values)));

        if ($child !== null && in_array($line, ['expenses', 'bank', 'other_income', 'founder'], true)) {
            $key = ['expenses' => 'expenses', 'bank' => 'bank', 'other_income' => 'other_income', 'founder' => 'founder'][$line];
            $value = $set($key)[$child] ?? 0.0;
            $title = $line === 'bank' ? FlowClassifier::groupLabel($child) : $child;

            return [$title, __('Bu kateqoriyanın həmin dövrdəki əməliyyatlarının cəmi — siyahı aşağıdadır.'), [], $value, 'money'];
        }

        return match ($line) {
            'sales' => [__('Satışlar'), __('Σ Yekun_Mebleg — satış faylında «Satış» tipli sətirlər (endirim çıxılmış). Hədiyyə və daxili istifadə sətirləri satış sayılmır.'), [
                $c(__('Endirimsiz məbləğ (Σ Mebleg)'), (float) ($totals->sales_gross ?? 0)),
                $c(__('Endirim'), -((float) ($totals->sales_gross ?? 0) - $sum('sales')), '−'),
            ], $sum('sales'), 'money'],
            'returns' => [__('Qaytarma'), __('Σ müştəri qaytarmalarının yekun məbləği (05 Qaytarmalar faylı, «müştəri» tipli). Daxili qaytarmalar daxil deyil.'), [], $sum('returns'), 'money'],
            'net_sales' => [__('Xalis satış'), __('Satışlar − Qaytarma'), [
                $c(__('Satışlar'), $sum('sales')), $c(__('Qaytarma'), -$sum('returns'), '−'),
            ], $netSales, 'money'],
            'sale_cogs' => [__('Satılan malın mayası'), __('Σ (miqdar × satış anındakı hərəkətli orta maya) satış sətirləri üzrə − qaytarılan malların mayası.'), [
                $c(__('Satılan malların mayası'), (float) ($totals->sale_cogs ?? 0)),
                $c(__('Qaytarılan malların mayası'), -(float) ($totals->return_cogs ?? 0), '−'),
            ], $sum('sale_cogs'), 'money'],
            'gift_cogs' => [__('Hədiyyə / daxili istifadə mayası'), __('«Hədiyyə / pulsuz» və «Daxili istifadə» sətirlərində verilən malın mayası (miqdar × hərəkətli orta maya). Gəlir gətirmir, brüt mənfəəti azaldır.'), [], $sum('gift_cogs'), 'money'],
            'gross_profit' => [__('Brüt mənfəət'), __('Xalis satış − Satılan malın mayası − Hədiyyə / daxili istifadə mayası'), [
                $c(__('Xalis satış'), $netSales), $c(__('Satılan malın mayası'), -$sum('sale_cogs'), '−'), $c(__('Hədiyyə / daxili istifadə mayası'), -$sum('gift_cogs'), '−'),
            ], $grossProfit, 'money'],
            'gross_margin' => [__('Brüt marja'), __('Brüt mənfəət ÷ Xalis satış'), [
                $c(__('Brüt mənfəət'), $grossProfit), $c(__('Xalis satış'), $netSales, '÷'),
            ], abs($netSales) > 0.005 ? $grossProfit / $netSales : 0.0, 'pct'],
            'expenses' => [__('Əməliyyat xərcləri'), __('Kassa (08 Xərclər faylı): «Xərc» tipli və «Mənfəətə təsir = Bəli» olan əməliyyatlar, kateqoriya üzrə (məxaric − mədaxil). Kateqoriyaya klik — əməliyyatlar.'),
                $categories($set('expenses'), ''), $sum('expenses'), 'money'],
            'bank' => [__('Rəsmi xərclər (bank)'), __('Mühasibat Cash Flow-da bankdan ödənilmiş və «Əməliyyat xərci» təsnifatlı ödənişlər, qrup üzrə. Mal alışı, vergi, təsisçi və əsas vəsait daxil deyil. Qrupa klik — ödənişlər.'),
                $categories($set('bank'), '', true), $sum('bank_expenses'), 'money'],
            'other_income' => [__('Digər gəlirlər'), __('Kassa: «Mədaxil» tipli və mənfəətə təsir edən əməliyyatlar (mədaxil − məxaric), kateqoriya üzrə.'),
                $categories($set('other_income'), ''), $sum('other_income'), 'money'],
            'export' => [__('Re-eksport nəticəsi (rəsmi MZ)'), __('Mühasibat MZ: AR hüdudlarından kənar gəlir (217.2) − onunla bağlı xərclər (232.1). İllik məbləğ aylara xarici alıcının (OOO …) bank ödənişlərinin payı ilə bölünür.'), [
                $c(__('Gəlir — AR hüdudlarından kənar satış'), $sum('export_revenue')), $c(__('Xərc — re-eksportla bağlı (maya, daşınma)'), -$sum('export_cost'), '−'),
            ], $sum('export_result'), 'money'],
            'official_extra' => [__('Rəsmi gəlir — sistemdə olmayan satış (bank)'), __('Tutuşdurma səhifəsində «Sistemdə olmayan satış — P&L-ə əlavə» seçilmiş ödəyicilərin bank daxilolmaları. Mayası məlum deyil — tam məbləğ mənfəətə düşür.'), [], $sum('official_extra'), 'money'],
            'net_profit' => [__('Xalis mənfəət'), __('Brüt mənfəət + Digər gəlirlər − Əməliyyat xərcləri − Rəsmi xərclər (bank) + Re-eksport nəticəsi + Rəsmi gəlir'), array_values(array_filter([
                $c(__('Brüt mənfəət'), $grossProfit), $c(__('Digər gəlirlər'), $sum('other_income'), '+'), $c(__('Əməliyyat xərcləri'), -$sum('expenses'), '−'),
                $c(__('Rəsmi xərclər (bank)'), -$sum('bank_expenses'), '−'), $c(__('Re-eksport nəticəsi'), $sum('export_result'), '+'), $c(__('Rəsmi gəlir — sistemdə olmayan satış'), $sum('official_extra'), '+'),
            ], fn ($x) => abs($x['value']) > 0.005 || $x['sign'] === '')), $sum('net_profit'), 'money'],
            'net_margin' => [__('Xalis marja'), __('Xalis mənfəət ÷ (Xalis satış + Re-eksport gəliri + Rəsmi gəlir)'), [
                $c(__('Xalis mənfəət'), $sum('net_profit')), $c(__('Xalis satış + re-eksport + rəsmi gəlir'), $marginBase, '÷'),
            ], abs($marginBase) > 0.005 ? $sum('net_profit') / $marginBase : 0.0, 'pct'],
            'founder' => [__('Təsisçiyə ödənişlər'), __('Kassa: «Təsisçiyə ödəniş» tipli əməliyyatlar — xərc deyil, mənfəətin bölüşdürülməsidir.'), $categories($set('founder'), ''), $sum('founder'), 'money'],
            'retained' => [__('Bölüşdürülməmiş mənfəət'), __('Xalis mənfəət − Təsisçiyə ödənişlər'), [
                $c(__('Xalis mənfəət'), $sum('net_profit')), $c(__('Təsisçiyə ödənişlər'), -$sum('founder'), '−'),
            ], $sum('retained'), 'money'],
        };
    }

    /**
     * Mənbə məlumatları: rəqəmi yaradan müştərilər, mallar, kassa əməliyyatları və ya bank ödənişləri.
     *
     * @return array{title: string, columns: list<string>, rows: list<list<mixed>>, note: string|null}|null
     */
    private function records(string $line, ReportFilters $cell, ?string $child, callable $set): ?array
    {
        $raw = fn () => DB::query()->fromSub(SalesSummaryQuery::rawRows($cell->fromDate(), $cell->toDate()), 's')
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->tap(fn ($q) => SalesSummaryQuery::applyDimensions($q, $cell, 's', 'p'));
        $limit = 40;

        switch ($line) {
            case 'sales':
            case 'returns':
                $kind = $line === 'sales' ? 'sale' : 'return_customer';
                $rows = $raw()->leftJoin('customers as c', 'c.id', '=', 's.customer_id')->where('s.kind', $kind)
                    ->groupBy('s.customer_id', 'c.name')->selectRaw('c.name AS name, COUNT(*) AS n, SUM(s.net_amount) AS amount')
                    ->orderByDesc('amount')->get();

                return $this->table($line === 'sales' ? __('Müştərilər üzrə') : __('Qaytaran müştərilər'), [__('Müştəri'), __('Sətir'), __('Məbləğ')],
                    $rows->take($limit)->map(fn ($r) => [$r->name ?? '—', Fmt::number($r->n), (float) $r->amount])->all(), $rows->count(), $limit);
            case 'sale_cogs':
            case 'gift_cogs':
                $kinds = $line === 'sale_cogs' ? ['sale', 'return_customer'] : ['gift', 'internal'];
                $rows = $raw()->whereIn('s.kind', $kinds)->groupBy('s.product_id', 'p.code', 'p.name')
                    ->selectRaw("p.code AS code, p.name AS name,
                        SUM(CASE WHEN s.kind = 'return_customer' THEN -s.qty ELSE s.qty END) AS qty,
                        SUM(CASE WHEN s.kind = 'return_customer' THEN -s.cogs ELSE s.cogs END) AS cogs")
                    ->orderByDesc('cogs')->get();

                return $this->table(__('Mallar üzrə (qaytarma çıxılıb)'), [__('Mal'), __('Miqdar'), __('Orta maya'), __('Maya')],
                    $rows->take($limit)->map(fn ($r) => [trim(($r->code ?? '').' '.($r->name ?? '—')), Fmt::qty($r->qty), abs((float) $r->qty) > 0.0001 ? (float) $r->cogs / (float) $r->qty : 0.0, (float) $r->cogs])->all(), $rows->count(), $limit);
            case 'expenses':
            case 'other_income':
            case 'founder':
                if ($child === null) {
                    return null;
                }
                $kind = ['expenses' => 'expense', 'other_income' => 'income', 'founder' => 'founder'][$line];
                $query = ProfitLossService::profitCash($cell, $kind);
                $child === '—' ? $query->whereNull('c.category_name') : $query->where('c.category_name', $child);
                $rows = $query->orderBy('c.date')->orderBy('c.id')->get(['c.date', 'c.subcategory', 'c.description', 'c.income', 'c.expense', 'c.direction']);

                return $this->table(__('Kassa əməliyyatları'), [__('Tarix'), __('Təsvir'), __('Məxaric'), __('Mədaxil')],
                    $rows->map(fn ($r) => [Fmt::date($r->date), trim(($r->subcategory ? $r->subcategory.' · ' : '').($r->description ?? '')) ?: '—', (float) $r->expense, (float) $r->income])->all(), $rows->count(), 500);
            case 'bank':
                if ($child === null) {
                    return null;
                }
                $classifier = new FlowClassifier;
                $rows = DB::table('bank_flow_lines')->where('section', 'payment')->whereBetween('period', [$cell->fromPeriod(), $cell->toPeriod()])
                    ->orderBy('period')->orderBy('row_no')->get()
                    ->filter(function ($r) use ($classifier, $child) {
                        $c = $classifier->classify($r->counterparty, 'payment');

                        return $c['type'] === FlowClassifier::EXPENSE && ($c['group'] ?: 'Sair xərclər') === $child;
                    });

                return $this->table(__('Bank ödənişləri (mühasibat Cash Flow)'), [__('Ay'), __('Kontragent'), __('Məbləğ')],
                    $rows->map(fn ($r) => [Fmt::period($r->period), $r->counterparty, (float) $r->amount])->values()->all(), $rows->count(), 500);
            case 'official_extra':
                $rows = collect(app(OfficialRevenueService::class)->payers((int) substr($cell->fromPeriod(), 0, 4)))
                    ->merge(substr($cell->fromPeriod(), 0, 4) !== substr($cell->toPeriod(), 0, 4) ? app(OfficialRevenueService::class)->payers((int) substr($cell->toPeriod(), 0, 4)) : [])
                    ->where('decision', OfficialRevenueService::EXTRA)
                    ->map(fn ($p) => [$p->counterparty, (float) collect($p->months)->filter(fn ($v, $m) => $m >= $cell->fromPeriod() && $m <= $cell->toPeriod())->sum()])
                    ->filter(fn ($r) => abs($r[1]) > 0.005)->values();

                return $this->table(__('Ödəyicilər'), [__('Ödəyici'), __('Məbləğ')], $rows->all(), $rows->count(), 500);
            case 'export':
                $years = range((int) substr($cell->fromPeriod(), 0, 4), (int) substr($cell->toPeriod(), 0, 4));
                $rows = [];
                foreach ($years as $year) {
                    $weights = collect(ProfitLossService::exportWeights($year))->filter(fn ($w, $p) => $p >= $cell->fromPeriod() && $p <= $cell->toPeriod())->sum();
                    $pl = DB::table('acc_pl_lines')->where('year', $year)->get()->filter(fn ($l) => ProfitLossService::isExportLine($l));
                    if ($pl->isNotEmpty()) {
                        $rows[] = [(string) $year, (float) $pl->where('section', 'income')->sum('amount'), (float) $pl->where('section', 'expense')->sum('amount'), Fmt::pct($weights)];
                    }
                }

                return $this->table(__('MZ (illik) və bu dövrə düşən pay'), [__('İl'), __('MZ gəlir'), __('MZ xərc'), __('Pay')], $rows, count($rows), 50);
            default:
                return null;
        }
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return array{title: string, columns: list<string>, rows: list<list<mixed>>, note: string|null}
     */
    private function table(string $title, array $columns, array $rows, int $count, int $limit): array
    {
        return ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'note' => $count > $limit ? __('Ən böyük :n sətir göstərilir (cəmi :total).', ['n' => $limit, 'total' => $count]) : null];
    }

    /**
     * Xananın dövrü: seçilmiş filtrlə ay / il kəsişməsi.
     */
    private function cellFilters(ReportFilters $filters, string $col, string $granularity): ReportFilters
    {
        if ($col === 'total') {
            return $filters;
        }
        $start = $granularity === 'year' || strlen($col) === 4 ? Carbon::create((int) $col)->startOfYear() : Carbon::parse($col.'-01')->startOfMonth();
        $end = strlen($col) === 4 ? $start->copy()->endOfYear() : $start->copy()->endOfMonth();
        $cell = clone $filters;
        $cell->from = $filters->from->greaterThan($start) ? $filters->from->copy() : $start;
        $cell->to = $filters->to->lessThan($end) ? $filters->to->copy() : $end;

        return $cell;
    }

    private function periodLabel(string $col, ReportFilters $filters): string
    {
        return match (true) {
            $col === 'total' => $filters->label(),
            strlen($col) === 4 => __(':year ili', ['year' => $col]),
            default => Fmt::period($col),
        };
    }
}
