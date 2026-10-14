<?php

namespace App\Http\Controllers;

use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Services\ChartLedgerService;
use App\Services\FlowClassifier;
use App\Services\LedgerPartyService;
use App\Services\MzDetailService;
use App\Services\OfficialRevenueService;
use App\Services\ProfitLossService;
use App\Services\ReconciliationService;
use App\Support\ChartOfAccounts;
use App\Support\Fmt;
use App\Support\NameNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Mühasibat bölməsi: hesablar planı, balans, vergi üzrə mənfəət-zərər (MZ), Cash Flow və ödənişlərin təsnifatı.
 */
class AccountingController extends Controller
{
    public function chart(Request $request, ChartLedgerService $ledger): View
    {
        $year = $this->year($request);
        $only = $request->boolean('only');
        $section = (string) $request->query('section', '');
        $q = trim((string) $request->query('q', ''));
        $accounts = DB::table('chart_accounts')
            ->when($section !== '', fn ($query) => $query->where('section', $section))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('code', 'like', $q.'%')->orWhere('name', 'like', '%'.$q.'%')))
            ->orderByRaw("CASE WHEN section = '0' THEN 1 ELSE 0 END")->orderBy('code')
            ->get();

        // balans faylındakı köhnə hesabların məbləği yeni hesablara bağlanır
        $balances = $year === null ? collect() : DB::table('acc_balance_lines as b')
            ->join('legacy_accounts as l', 'l.code', '=', 'b.legacy_code')
            ->where('b.year', $year)
            ->groupBy('l.chart_code')
            ->selectRaw('l.chart_code, SUM(b.amount) AS amount')
            ->pluck('amount', 'chart_code');
        // rəsmi gəlir / xərc hesabları (6–9 bölmələr) MZ maddələrindən
        foreach ($year === null ? [] : $ledger->officialTurnover($year) as $code => $parts) {
            if (! $balances->has($code)) {
                $balances[$code] = array_sum(array_column($parts, 'amount'));
            }
        }
        // idarəetmə: sistem importları həmin tarixə (balans tarixi; fayl yoxdursa bu gün)
        $date = $this->ledgerDate($year);
        $management = collect($ledger->management($date))->map(fn ($parts) => (float) array_sum(array_column($parts, 'amount')));
        if ($only) {
            $accounts = $accounts->filter(fn ($a) => $balances->has($a->code) || $management->has($a->code))->values();
        }

        // mənfəət: Bölmə 6 (gəlirlər, 602 / 603 çıxılır) − Bölmə 7 (xərclər) — hər iki sütun üçün
        $signed = fn (string $code, float $v) => in_array($code, ChartLedgerService::CONTRA_INCOME, true) ? -$v : $v;
        $sectionSum = fn (Collection $values, string $prefix) => (float) $values->filter(fn ($v, $code) => str_starts_with((string) $code, $prefix))
            ->map(fn ($v, $code) => $signed((string) $code, (float) $v))->sum();
        $profit = [];
        foreach (['official' => $balances, 'management' => $management] as $key => $values) {
            $profit[$key] = ['income' => $sectionSum($values, '6'), 'expense' => $sectionSum($values, '7')];
            $profit[$key]['result'] = $profit[$key]['income'] - $profit[$key]['expense'];
        }

        return view('accounting.chart', [
            'profit' => $profit,
            'management' => $management,
            'ledgerDate' => $date,
            'only' => $only,
            'title' => __('Hesablar planı'),
            'accounts' => $accounts->groupBy('section'),
            'sections' => ChartOfAccounts::sections(),
            'groups' => ChartOfAccounts::groups(),
            'balances' => $balances,
            'legacy' => DB::table('legacy_accounts')->orderByRaw('LENGTH(code)')->orderBy('code')->get(),
            'chartNames' => DB::table('chart_accounts')->pluck('name', 'code'),
            'section' => $section,
            'q' => $q,
            'year' => $year,
            'years' => $this->years(),
            'yearLabels' => $this->asOfLabels(),
        ]);
    }

    /**
     * Hesablar planındakı qalığın mənbəyi: hansı köhnə balans sətirlərindən və sənədlərdən yaranıb (popup).
     */
    /**
     * Balans — idarəetmə məlumatları (sistem importları) üzrə: aktivlər = öhdəliklər + kapital.
     * Müqayisə üçün eyni hesabın rəsmi (mühasibat balansı) qalığı yanında göstərilir.
     */
    private function managementBalanceView(?int $year, ChartLedgerService $ledger): View
    {
        $date = $this->ledgerDate($year);
        $parts = $ledger->managementBalance($date);
        $names = DB::table('chart_accounts')->pluck('name', 'code');
        $official = $year === null ? collect() : DB::table('acc_balance_lines as b')->join('legacy_accounts as l', 'l.code', '=', 'b.legacy_code')
            ->where('b.year', $year)->groupBy('l.chart_code')->selectRaw('l.chart_code, SUM(b.amount) AS amount')->pluck('amount', 'chart_code');
        $row = fn (string $code, int $sign = 1) => (object) [
            'code' => $code,
            'name' => $names[$code] ?? $code,
            'amount' => $sign * (float) array_sum(array_column($parts[$code] ?? [], 'amount')),
            'official' => isset($official[$code]) ? (float) $official[$code] : null,
            'parts' => $parts[$code] ?? [],
        ];
        $assets = collect([$row('205'), $row('211'), $row('221')]);
        $liabilities = collect([$row('542'), $row('301'), $row('343'), $row('341'), $row('344', -1)]);
        $liabilities->firstWhere('code', '301')->name = __('Keçmiş illərin mənfəəti');

        return view('accounting.balance-mgmt', [
            'title' => __('Balans'),
            'year' => $year,
            'years' => $this->years(),
            'date' => $date,
            'assets' => $assets,
            'liabilities' => $liabilities,
        ]);
    }

    /**
     * Hesabın idarəetmə qalığının / dövriyyəsinin mənbəyi — sistem importlarından komponentlər (popup).
     */
    public function managementSource(Request $request, string $code, ChartLedgerService $ledger): View
    {
        $year = $this->year($request);
        $account = DB::table('chart_accounts')->where('code', $code)->firstOrFail();
        $date = $this->ledgerDate($year);
        $parts = $ledger->managementBalance($date)[$code] ?? [];

        return view($request->header('X-Modal') ? 'accounting.mgmt-source' : 'accounting.mgmt-source-page', [
            'title' => __('Hesab :code — idarəetmə (sistem importları)', ['code' => $code]),
            'account' => $account,
            'year' => $year,
            'date' => $date,
            'parts' => $parts,
            'detail' => $ledger->detail($code, $date),
            'total' => (float) array_sum(array_column($parts, 'amount')),
            'isTurnover' => in_array(substr($code, 0, 1), ['6', '7', '8', '9'], true) || in_array($code, ['341', '344'], true),
        ]);
    }

    /**
     * Hesablar planının tarixi: seçilmiş ilin balans tarixi (məs. 30.09.2026), fayl yoxdursa bu gün.
     */
    private function ledgerDate(?int $year): Carbon
    {
        $asOf = $year === null ? null : DB::table('acc_balance_lines')->where('year', $year)->max('as_of');

        return $asOf ? Carbon::parse($asOf) : ($year !== null ? Carbon::create($year)->endOfYear() : now());
    }

    public function accountSource(Request $request, string $code): View
    {
        $year = $this->year($request);
        $account = DB::table('chart_accounts')->where('code', $code)->firstOrFail();
        $legacy = DB::table('legacy_accounts')->where('chart_code', $code)->orderBy('code')->get();
        $legacyCodes = $legacy->pluck('code')->all();

        // bütün tarixlər üzrə (illərin müqayisəsi), seçilmiş il yuxarıda
        $lines = DB::table('acc_balance_lines')->whereIn('legacy_code', $legacyCodes)
            ->orderByDesc('year')->orderBy('row_no')->get();
        $ledger = DB::table('acc_ledger_lines')->where('year', $year)->whereIn('legacy_code', $legacyCodes)
            ->orderBy('legacy_code')->orderBy('row_no')->get()->groupBy('legacy_code');

        return view($request->header('X-Modal') ? 'accounting.source' : 'accounting.source-page', [
            'title' => __('Hesab :code — qalığın mənbəyi', ['code' => $code]),
            'account' => $account,
            'legacy' => $legacy,
            'year' => $year,
            'yearLabels' => $this->asOfLabels(),
            'current' => $lines->where('year', $year)->values(),
            'byYear' => $lines->groupBy('year')->map(fn (Collection $l) => (float) $l->sum('amount')),
            'ledger' => $ledger,
            // gəlir / xərc hesabları üçün MZ maddələri
            'mzParts' => $year === null ? [] : (app(ChartLedgerService::class)->officialTurnover($year)[$code] ?? []),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'regex:/^\d{3,6}$/', 'unique:chart_accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'nature' => ['required', Rule::in(['A', 'P', 'K', 'G', 'X', 'N', 'B'])],
        ]);
        DB::table('chart_accounts')->insert($data + [
            'section' => ChartOfAccounts::sectionOf($data['code']),
            'group_code' => ChartOfAccounts::groupOf($data['code']),
            'is_active' => true, 'user_edited' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('status', __('Hesab əlavə olundu: :code', ['code' => $data['code']]));
    }

    public function updateAccount(Request $request, int $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);
        DB::table('chart_accounts')->where('id', $account)->update($data + ['user_edited' => true, 'updated_at' => now()]);

        return back()->with('status', __('Hesab yeniləndi.'));
    }

    public function balance(Request $request, ChartLedgerService $ledger): View
    {
        $year = $this->year($request);
        if ($request->query('view') === 'mgmt') {
            return $this->managementBalanceView($year, $ledger);
        }
        $lines = DB::table('acc_balance_lines as b')
            ->leftJoin('legacy_accounts as l', 'l.code', '=', 'b.legacy_code')
            ->leftJoin('chart_accounts as c', 'c.code', '=', 'l.chart_code')
            ->where('b.year', $year)->orderBy('b.row_no')
            ->get(['b.*', 'l.chart_code', 'l.note as map_note', 'c.name as chart_name']);
        $ledgerCodes = DB::table('acc_ledger_lines')->where('year', $year)->distinct()->pluck('legacy_code')->flip();

        return view('accounting.balance', [
            'title' => __('Balans'),
            'year' => $year,
            'years' => $this->years(),
            'assets' => $lines->where('side', 'asset')->values(),
            'liabilities' => $lines->where('side', 'liability')->values(),
            'asOf' => $lines->first()->as_of ?? null,
            'ledgerCodes' => $ledgerCodes,
        ]);
    }

    public function ledger(Request $request, string $code): View
    {
        $year = $this->year($request);
        $rows = DB::table('acc_ledger_lines')->where('year', $year)->where('legacy_code', $code)->orderBy('row_no')->get();

        return view($request->header('X-Modal') ? 'accounting.ledger' : 'accounting.ledger-page', [
            'title' => __('Hesab :code — qalıqlar', ['code' => $code]),
            'code' => $code,
            'year' => $year,
            'rows' => $rows,
            'chart' => DB::table('legacy_accounts as l')->leftJoin('chart_accounts as c', 'c.code', '=', 'l.chart_code')
                ->where('l.code', $code)->first(['l.chart_code', 'c.name']),
        ]);
    }

    /**
     * Dövriyyə vərəqindəki kontragent / sənəd: mühasibat sənədləri, bank hərəkətləri, sistemdəki qarşılığı (popup).
     */
    public function ledgerParty(Request $request, string $code, LedgerPartyService $service): View
    {
        $party = (string) $request->query('party', '');
        $document = $request->filled('doc') ? (string) $request->query('doc') : null;
        abort_if($party === '', 404);

        return view($request->header('X-Modal') ? 'accounting.party' : 'accounting.party-page', array_merge($service->party($code, $party, $document), [
            'title' => $document ?? $party,
            'code' => $code,
            'party' => $party,
            'document' => $document,
            'year' => $this->year($request),
        ]));
    }

    /**
     * MZ xanasının detalı (popup): maddə — bank / sistem qarşılığı; qrup sətri — maddələrin tərkibi.
     */
    public function profitLossDetail(Request $request, MzDetailService $service): View
    {
        $year = (int) $request->query('year');
        abort_unless(in_array($year, $this->years(), true), 404);
        $kind = in_array($request->query('kind'), ['income', 'cogs', 'opex', 'depreciation'], true) ? (string) $request->query('kind') : null;
        $data = $kind !== null
            ? ['groupLines' => $service->group($year, $kind), 'kind' => $kind]
            : $service->line($year, (string) $request->query('code', ''), (string) $request->query('name', ''));
        $title = $kind !== null
            ? ['income' => __('Gəlirlər'), 'cogs' => __('Malın dəyəri (daxili + re-eksport)'), 'opex' => __('Əməliyyat xərcləri'), 'depreciation' => __('Amortizasiya')][$kind]
            : trim($request->query('code', '').' · '.($data['line']->name ?? $request->query('name', '')), ' ·');

        return view($request->header('X-Modal') ? 'accounting.mz-detail' : 'accounting.mz-detail-page', $data + ['title' => $title, 'year' => $year]);
    }

    public function profitLoss(Request $request, ProfitLossService $pnl): View
    {
        $year = $this->year($request);
        $lines = DB::table('acc_pl_lines')->where('year', $year)->orderBy('row_no')->get();

        // eyni il üzrə idarəetmə (sistem) rəqəmləri ilə müqayisə
        $comparison = null;
        if ($year !== null) {
            $filters = new ReportFilters(Carbon::create($year)->startOfYear(), Carbon::create($year)->endOfYear(), 'custom', direction: 'VALVO');
            $sales = SalesSummaryQuery::totals($filters);
            $report = $pnl->build($filters, 'year');
            $col = (string) $year;
            $comparison = [
                'net_sales' => $sales->net_sales,
                'cogs' => $sales->cogs,
                'gross_profit' => $sales->gross_profit,
                'expenses' => ($report['lines']['expenses'][$col] ?? 0) + ($report['lines']['bank_expenses'][$col] ?? 0),
                'net_profit' => $report['lines']['net_profit'][$col] ?? 0,
            ];
        }

        return view('accounting.pl', [
            'title' => __('Mənfəət-zərər (vergi bəyannaməsi)'),
            'year' => $year,
            'years' => $this->years(),
            'income' => $lines->where('section', 'income')->values(),
            'expenses' => $lines->where('section', 'expense')->values(),
            'result' => $lines->firstWhere('section', 'result'),
            'comparison' => $comparison,
            'statement' => $this->plStatement(),
        ]);
    }

    /**
     * MZ illər üzrə, idarəetmə P&L-i kimi: Gəlirlər → Malın dəyəri → Brüt mənfəət → Əməliyyat xərcləri → Amortizasiya → Mənfəət.
     * Hər qrup açılır — içində vergi bəyannaməsi kodları ilə maddələr.
     *
     * @return array{columns: list<int>, labels: array<int, string>, lines: list<array<string, mixed>>}
     */
    private function plStatement(): array
    {
        $all = DB::table('acc_pl_lines')->orderBy('row_no')->get();
        $columns = $all->pluck('year')->map(fn ($y) => (int) $y)->unique()->sort()->values()->all();
        $kind = fn (object $l): string => MzDetailService::kind($l);
        $byKind = fn (string $k) => $all->where('section', '!=', 'result')->filter(fn ($l) => $kind($l) === $k);
        $sumBy = fn (Collection $lines) => collect($columns)->mapWithKeys(fn ($y) => [$y => (float) $lines->where('year', $y)->sum('amount')])->all();
        // eyni maddə bütün illərdə bir sətirdə (kod + ad)
        $children = fn (Collection $lines, int $sign = 1) => $lines->groupBy(fn ($l) => ($l->tax_code ?: '').'|'.NameNormalizer::normalize($l->name))
            ->map(fn (Collection $g) => [
                'label' => trim(($g->first()->tax_code ? $g->first()->tax_code.' · ' : '').$g->sortBy('year')->last()->name),
                'code' => (string) $g->first()->tax_code,
                'name' => $g->sortBy('year')->last()->name,
                'values' => array_map(fn ($v) => $sign * $v, $sumBy($g)),
            ])->values()->all();
        $neg = fn (array $values) => array_map(fn ($v) => -$v, $values);

        $income = $sumBy($byKind('income'));
        $cogs = $sumBy($byKind('cogs'));
        $opex = $sumBy($byKind('opex'));
        $depreciation = $sumBy($byKind('depreciation'));
        $gross = array_map(fn ($y) => $income[$y] - $cogs[$y], array_combine($columns, $columns) ?: []);
        $result = [];
        foreach ($columns as $y) {
            $fileResult = $all->where('year', $y)->firstWhere('section', 'result');
            $result[$y] = $fileResult ? (float) $fileResult->amount : $gross[$y] - $opex[$y] - $depreciation[$y];
        }
        $ratio = fn (array $a) => array_map(fn ($y) => abs($income[$y]) > 0.005 ? $a[$y] / $income[$y] : 0.0, array_combine($columns, $columns) ?: []);

        $lines = [
            ['kind' => 'income', 'label' => __('Gəlirlər'), 'values' => $income, 'style' => 'expand', 'type' => 'money', 'children' => $children($byKind('income'))],
            ['kind' => 'cogs', 'label' => __('− Malın dəyəri (daxili + re-eksport)'), 'values' => $neg($cogs), 'style' => 'expand', 'type' => 'money', 'children' => $children($byKind('cogs'), -1)],
            ['label' => __('Brüt mənfəət'), 'values' => $gross, 'style' => 'subtotal', 'type' => 'money', 'children' => []],
            ['label' => __('Brüt marja'), 'values' => $ratio($gross), 'style' => 'muted', 'type' => 'pct', 'children' => []],
            ['kind' => 'opex', 'label' => __('− Əməliyyat xərcləri'), 'values' => $neg($opex), 'style' => 'expand', 'type' => 'money', 'children' => $children($byKind('opex'), -1)],
            ['kind' => 'depreciation', 'label' => __('− Amortizasiya'), 'values' => $neg($depreciation), 'style' => 'expand', 'type' => 'money', 'children' => $children($byKind('depreciation'), -1)],
            ['label' => __('Hesabat dövründə mənfəət (zərər)'), 'values' => $result, 'style' => 'total', 'type' => 'money', 'children' => []],
            ['label' => __('Rentabellik'), 'values' => $ratio($result), 'style' => 'muted', 'type' => 'pct', 'children' => []],
        ];
        $labels = $this->asOfLabels();

        return [
            'columns' => $columns,
            'labels' => collect($columns)->mapWithKeys(fn ($y) => [$y => isset($labels[$y]) && ! str_starts_with($labels[$y], '31.12') ? $y.' ('.$labels[$y].')' : (string) $y])->all(),
            'lines' => array_values(array_filter($lines, fn ($l) => $l['type'] === 'pct' || $l['style'] !== 'expand' || array_sum(array_map('abs', $l['values'])) > 0.005)),
        ];
    }

    /**
     * İllər üzrə müqayisə: balans, MZ və Cash Flow (növ üzrə) yan-yana.
     */
    public function overview(): View
    {
        $years = collect($this->years())->sort()->values()->all();
        $classifier = new FlowClassifier;
        $flow = [];
        foreach (DB::table('bank_flow_lines')->whereIn('section', ['income', 'payment'])->get() as $f) {
            $type = $classifier->classify($f->counterparty, $f->section)['type'];
            $y = (int) $f->year;
            $flow[$y][$f->section] = ($flow[$y][$f->section] ?? 0) + $f->amount;
            $flow[$y][$f->section.'|'.$type] = ($flow[$y][$f->section.'|'.$type] ?? 0) + $f->amount;
        }
        $closing = DB::table('bank_flow_lines')->where('section', 'closing')->where('amount', '!=', 0)->orderBy('period')->get()
            ->groupBy('year')->map(fn (Collection $g) => (float) $g->last()->amount);

        $balance = DB::table('acc_balance_lines')->orderBy('row_no')->get();
        $pl = DB::table('acc_pl_lines')->orderBy('row_no')->get();
        $plTotals = fn (int $y, string $section) => (float) $pl->where('year', $y)->where('section', $section)->sum('amount');
        $plResult = fn (int $y) => (float) ($pl->where('year', $y)->firstWhere('section', 'result')->amount ?? $plTotals($y, 'income') - $plTotals($y, 'expense'));

        $metric = fn (string $label, callable $value, string $tone = '', bool $strong = false) => (object) [
            'label' => $label, 'tone' => $tone, 'strong' => $strong,
            'values' => collect($years)->mapWithKeys(fn ($y) => [$y => $value($y)])->all(),
        ];
        $f = fn (string $key) => fn (int $y) => (float) ($flow[$y][$key] ?? 0);
        $groups = [
            __('Balans') => [
                $metric(__('Aktivlər cəmi (balans valyutası)'), fn ($y) => (float) $balance->where('year', $y)->where('side', 'asset')->sum('amount'), '', true),
                $metric(__('Pul vəsaitləri (dövrün sonuna)'), fn ($y) => (float) ($closing[$y] ?? 0)),
            ],
            __('Mənfəət-zərər (vergi)') => [
                $metric(__('Gəlirlər'), fn ($y) => $plTotals($y, 'income'), 'pos'),
                $metric(__('Xərclər'), fn ($y) => $plTotals($y, 'expense'), 'neg'),
                $metric(__('Hesabat dövründə mənfəət (zərər)'), $plResult, 'sign', true),
            ],
            __('Cash Flow (bank)') => [
                $metric(__('Mədaxil'), $f('income'), 'pos', true),
                $metric('· '.__('Satış gəliri'), $f('income|revenue')),
                $metric('· '.__('Təsisçidən'), $f('income|financing')),
                $metric(__('Ödənişlər'), $f('payment'), 'neg', true),
                $metric('· '.__('Əməliyyat xərci'), $f('payment|expense'), 'neg'),
                $metric('· '.__('Mal alışı / gömrük (maya dəyəri)'), $f('payment|cogs')),
                $metric('· '.__('Vergi (xərc deyil)'), $f('payment|tax')),
                $metric('· '.__('Təsisçiyə (dividend / borc qaytarılması)'), $f('payment|financing')),
                $metric('· '.__('Əsas vəsait alışı'), $f('payment|capex')),
                $metric('· '.__('Müştəriyə geri ödəniş'), $f('payment|refund')),
                $metric('· '.__('Təsnif olunmayıb'), $f('payment|uncertain'), 'warn'),
            ],
        ];

        // sətir-sətir müqayisə: eyni köhnə hesab / vergi kodu bütün illərdə bir sətirdə
        $pivot = fn (Collection $lines, callable $key) => $lines->groupBy($key)->map(fn (Collection $g) => (object) [
            'code' => $g->last()->legacy_code ?? $g->last()->tax_code ?? null,
            'name' => $g->sortBy('year')->last()->name,
            'section' => $g->first()->side ?? $g->first()->section,
            'order' => $g->min('row_no'),
            'values' => $g->groupBy('year')->map(fn ($l) => (float) $l->sum('amount'))->all(),
        ])->sortBy('order')->values();

        return view('accounting.overview', [
            'title' => __('İllər üzrə müqayisə'),
            'years' => $years,
            'yearLabels' => $this->asOfLabels(),
            'groups' => $groups,
            'balanceRows' => $pivot($balance, fn ($l) => $l->side.'|'.($l->legacy_code ?: NameNormalizer::normalize($l->name))),
            'plRows' => $pivot($pl->where('section', '!=', 'result'), fn ($l) => $l->section.'|'.($l->tax_code ?: NameNormalizer::normalize($l->name))),
            'chart' => [
                'type' => 'bar',
                'categories' => array_map('strval', $years),
                'series' => [
                    ['name' => __('Gəlirlər'), 'data' => array_map(fn ($y) => round($plTotals($y, 'income'), 2), $years)],
                    ['name' => __('Xərclər'), 'data' => array_map(fn ($y) => round($plTotals($y, 'expense'), 2), $years)],
                    ['name' => __('Mənfəət (zərər)'), 'data' => array_map(fn ($y) => round($plResult($y), 2), $years)],
                ],
                'colors' => ['--chart-2', '--chart-6', '--chart-1'],
            ],
        ]);
    }

    /**
     * Sistem məlumatlarının mühasibat faylı ilə tutuşdurulması (il üzrə).
     */
    public function reconcile(Request $request, ReconciliationService $service, OfficialRevenueService $official): View
    {
        $year = $this->year($request);

        return view('accounting.reconcile', array_merge([
            'title' => __('Tutuşdurma: sistem ↔ mühasibat'),
            'year' => $year,
            'years' => $this->years(),
        ], $year === null ? [] : $service->build($year) + [
            'payers' => $official->payers($year),
            'openings' => $official->openings(),
            'decisionLabels' => OfficialRevenueService::labels(),
        ]));
    }

    /**
     * Rəsmi daxilolmanın P&L-ə münasibəti (ödəyici üzrə): sistemdə var / sistemdə olmayan satış / gəlir deyil / avtomatik.
     */
    public function decideRevenue(Request $request, OfficialRevenueService $official): RedirectResponse
    {
        $data = $request->validate([
            'normalized' => ['required', 'string', 'max:255'],
            'counterparty' => ['required', 'string', 'max:255'],
            'decision' => ['required', Rule::in(array_merge(OfficialRevenueService::DECISIONS, ['auto']))],
        ]);
        $official->decide($data['normalized'], $data['counterparty'], $data['decision'], $request->user()->id);

        return back()->with('status', __('«:name» üçün qərar saxlanıldı — P&L yeniləndi.', ['name' => $data['counterparty']]));
    }

    public function cashFlow(Request $request): View
    {
        $year = $this->year($request);
        $typeFilter = (string) $request->query('type', '');
        $view = $request->query('view') === 'sheet' ? 'sheet' : 'analysis';
        $sectionFilter = in_array($request->query('section'), ['income', 'payment', 'other', 'all'], true) ? (string) $request->query('section') : ($typeFilter !== '' ? 'all' : 'payment');
        $flows = DB::table('bank_flow_lines')->where('year', $year)->orderBy('row_no')->get();
        $months = $flows->pluck('period')->unique()->sort()->values()->all();
        $classifier = new FlowClassifier;

        $rows = $flows->whereIn('section', ['income', 'payment', 'other'])
            ->groupBy(fn ($r) => $r->section.'|'.$r->counterparty)
            ->map(function (Collection $group) use ($classifier) {
                $first = $group->first();
                $c = $classifier->classify($first->counterparty, $first->section);

                return (object) [
                    'section' => $first->section,
                    'counterparty' => $first->counterparty,
                    'normalized' => $first->normalized,
                    'row_no' => $first->row_no,
                    'months' => $group->groupBy('period')->map(fn ($g) => $g->sum('amount'))->all(),
                    'total' => $group->sum('amount'),
                    'type' => $c['type'],
                    'group' => $c['group'],
                    'note' => $c['note'],
                    'confidence' => $c['confidence'],
                ];
            })->sortBy('row_no')->values();

        $byType = $rows->groupBy(fn ($r) => $r->section.'|'.$r->type)->map(fn ($g) => $g->sum('total'));
        // P&L-ə «Rəsmi xərclər» kimi düşən ödənişlər (mal alışı / gömrük xaric), qrup üzrə
        $expenseByGroup = $rows->where('section', 'payment')->reject(fn ($r) => $r->type === 'cogs')
            ->groupBy(fn ($r) => FlowClassifier::pnlGroup(['type' => $r->type, 'group' => $r->group]))->map(fn ($g) => $g->sum('total'))->sortDesc();
        $paymentChart = [
            'type' => 'bar',
            'stacked' => true,
            'categories' => array_map(fn ($p) => Fmt::period($p), $months),
            'series' => collect(['expense', 'cogs', 'tax', 'capex', 'refund', 'uncertain'])
                ->map(fn ($type) => [
                    'name' => FlowClassifier::typeLabels()[$type],
                    'data' => array_map(fn ($p) => round($rows->where('section', 'payment')->where('type', $type)->sum(fn ($r) => $r->months[$p] ?? 0), 2), $months),
                ])->filter(fn ($s) => array_sum($s['data']) != 0)->values()->all(),
            'colors' => ['--chart-6', '--chart-1', '--chart-4', '--chart-5', '--chart-2', '--chart-3'],
        ];

        return view('accounting.cashflow', [
            'title' => __('Cash Flow (bank / mühasibat)'),
            'year' => $year,
            'years' => $this->years(),
            'months' => $months,
            'rows' => $rows->when($typeFilter !== '', fn ($c) => $c->where('type', $typeFilter))
                ->when($sectionFilter !== 'all', fn ($c) => $c->where('section', $sectionFilter))->values(),
            'sectionCounts' => $rows->countBy('section'),
            'sectionFilter' => $sectionFilter,
            'typeFilter' => $typeFilter,
            'byType' => $byType,
            'expenseByGroup' => $expenseByGroup,
            'opening' => $flows->where('section', 'opening')->sortBy('period')->first()?->amount,
            'closing' => $flows->where('section', 'closing')->sortBy('period')->last()?->amount,
            'balances' => DB::table('bank_flow_balances')->where('year', $year)->get()->groupBy('account'),
            'paymentChart' => $paymentChart,
            'view' => $view,
            'sheetRows' => $rows->groupBy('section'),
            'openingByMonth' => $flows->where('section', 'opening')->groupBy('period')->map(fn ($g) => (float) $g->sum('amount')),
            'closingByMonth' => $flows->where('section', 'closing')->groupBy('period')->map(fn ($g) => (float) $g->sum('amount')),
            'typeLabels' => FlowClassifier::typeLabels(),
            'groups' => FlowClassifier::expenseGroups(),
            'uncertainTotal' => $rows->where('section', 'payment')->where('type', 'uncertain')->sum('total'),
        ]);
    }

    public function classify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'normalized' => ['required', 'string', 'max:255'],
            'counterparty' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_merge(FlowClassifier::TYPES, ['reset']))],
            'expense_group' => ['nullable', 'string', 'max:255'],
        ]);
        if ($data['type'] === 'reset') {
            DB::table('flow_classifications')->where('normalized', $data['normalized'])->delete();

            return back()->with('status', __('«:name» üçün avtomatik təsnifat bərpa olundu.', ['name' => $data['counterparty']]));
        }
        DB::table('flow_classifications')->updateOrInsert(['normalized' => $data['normalized']], [
            'counterparty' => $data['counterparty'],
            'type' => $data['type'],
            'expense_group' => $data['type'] === FlowClassifier::EXPENSE ? ($data['expense_group'] ?: 'Sair xərclər') : null,
            'note' => __('Əl ilə təsnif edilib (:user)', ['user' => $request->user()->name]),
            'user_id' => $request->user()->id,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return back()->with('status', __('«:name» təsnif edildi — P&L dərhal yeniləndi.', ['name' => $data['counterparty']]));
    }

    private function year(Request $request): ?int
    {
        $years = $this->years();
        $year = (int) $request->query('year', 0);

        return in_array($year, $years, true) ? $year : ($years[0] ?? null);
    }

    /**
     * Balans tarixləri: il → «31.12.2025».
     *
     * @return array<int, string>
     */
    private function asOfLabels(): array
    {
        return DB::table('acc_balance_lines')->groupBy('year')->selectRaw('year, MAX(as_of) AS as_of')->pluck('as_of', 'year')
            ->mapWithKeys(fn ($date, $y) => [(int) $y => Fmt::date($date)])->all();
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        return DB::table('acc_balance_lines')->distinct()->pluck('year')
            ->merge(DB::table('bank_flow_lines')->distinct()->pluck('year'))
            ->map(fn ($y) => (int) $y)->unique()->sortDesc()->values()->all();
    }
}
