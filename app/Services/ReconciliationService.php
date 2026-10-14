<?php

namespace App\Services;

use App\Reports\ReportFilters;
use App\Reports\SalesSummaryQuery;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sistemdəki (Excel 01–08 importları) məlumatların mühasibat faylı (Balans / MZ / Cash Flow) ilə tutuşdurulması:
 * gəlirlər, xərclər (kassa ↔ bank ↔ MZ), alışlar və müştərilərin bank ödənişləri.
 */
class ReconciliationService
{
    public const STATUS_ADDED = 'added';

    public const STATUS_BOTH = 'both';

    public const STATUS_CASH_ONLY = 'cash_only';

    public const STATUS_OK = 'ok';

    public const STATUS_INFO = 'info';

    public function __construct(private ProfitLossService $pnl) {}

    /**
     * @return array{revenue: list<object>, expenses: list<object>, purchases: list<object>}
     */
    public function build(int $year): array
    {
        $filters = new ReportFilters(Carbon::create($year)->startOfYear(), Carbon::create($year)->endOfYear(), 'custom', direction: 'VALVO');
        $pl = DB::table('acc_pl_lines')->where('year', $year)->get();
        $classifier = new FlowClassifier;
        $flows = DB::table('bank_flow_lines')->where('year', $year)->whereIn('section', ['income', 'payment'])->get()
            ->map(function ($f) use ($classifier) {
                $c = $classifier->classify($f->counterparty, $f->section);
                $f->type = $c['type'];
                $f->group = $c['group'] ?: 'Sair xərclər';
                $f->pnl_group = $f->section === 'payment' ? FlowClassifier::pnlGroup($c) : null;

                return $f;
            });

        return [
            'revenue' => $this->revenue($filters, $year, $pl, $flows),
            'expenses' => $this->expenses($filters, $pl, $flows),
            'purchases' => $this->purchases($year, $pl, $flows),
        ];
    }

    /**
     * @return list<object>
     */
    private function revenue(ReportFilters $filters, int $year, Collection $pl, Collection $flows): array
    {
        $income = $pl->where('section', 'income');
        $domestic = (float) $income->filter(fn ($l) => str_starts_with((string) $l->tax_code, '200'))->sum('amount');
        $export = (float) $income->filter(fn ($l) => ProfitLossService::isExportLine($l))->sum('amount');
        $fx = (float) $income->filter(fn ($l) => in_array(substr((string) $l->tax_code, 0, 3), ['214', '219'], true))->sum('amount');
        $sales = SalesSummaryQuery::totals($filters);
        $period = [$year.'-01', $year.'-12'];
        $payments = DB::table('payments')->whereBetween('period', $period)->selectRaw('SUM(bank) AS bank, SUM(total) AS total')->first();
        $customerReceipts = (float) $flows->where('section', 'income')->where('type', 'revenue')
            ->reject(fn ($f) => str_starts_with($f->normalized, 'ooo '))->sum('amount');
        $exportReceipts = (float) $flows->where('section', 'income')->filter(fn ($f) => str_starts_with($f->normalized, 'ooo '))->sum('amount');
        $founder = (float) $flows->where('section', 'income')->where('type', 'financing')->sum('amount');
        $cashToBank = (float) DB::table('cash_transactions')->whereBetween('period', $period)->where('op_type', 'like', '%köçürmə%')->sum('expense');

        return [
            (object) ['key' => 'domestic', 'label' => __('Daxili satış (xalis)'), 'system' => (float) $sales->net_sales, 'official' => $domestic,
                'status' => self::STATUS_INFO, 'note' => __('Sistem: satış faylı (nağd + köçürmə, qaytarma çıxılıb). Rəsmi: MZ 200.1 — yalnız rəsmiləşdirilmiş satış. Fərq nağd satışlardan və ƏDV-dən yaranır.')],
            (object) ['key' => 'export', 'label' => __('Re-eksport (AR hüdudlarından kənar)'), 'system' => 0.0, 'official' => $export,
                'status' => $export > 0 ? self::STATUS_ADDED : self::STATUS_OK, 'note' => __('Satış faylında yoxdur. P&L-ə «Re-eksport nəticəsi» kimi əlavə olundu (MZ gəliri − MZ 232.1 xərci).')],
            (object) ['key' => 'fx', 'label' => __('Məzənnə fərqi (xalis)'), 'system' => 0.0, 'official' => $fx,
                'status' => self::STATUS_INFO, 'note' => __('Valyuta qalıqlarının yenidən qiymətləndirilməsi — pul hərəkəti deyil, P&L-ə əlavə edilmir. Reallaşmış kurs fərqi rəsmi xərclərdədir.')],
            (object) ['key' => 'bank_receipts', 'label' => __('Müştərilərdən bank daxilolmaları'), 'system' => (float) ($payments->bank ?? 0), 'official' => $customerReceipts,
                'status' => abs($customerReceipts - (float) ($payments->bank ?? 0)) > 1 ? self::STATUS_INFO : self::STATUS_OK,
                'note' => __('Sistem: 06 Ödənişlər faylının «bank» sütunu. Rəsmi: Cash Flow mədaxili (re-eksport və təsisçi xaric). Müştəri üzrə fərqlər aşağıdakı cədvəldədir.')],
            (object) ['key' => 'export_receipts', 'label' => __('Re-eksport alıcısından daxilolmalar'), 'system' => 0.0, 'official' => $exportReceipts,
                'status' => self::STATUS_INFO, 'note' => __('Xarici alıcının (OOO …) ödənişləri — müştəri borclarına aid deyil.')],
            (object) ['key' => 'founder', 'label' => __('Təsisçidən daxilolmalar'), 'system' => $cashToBank, 'official' => $founder,
                'status' => self::STATUS_INFO, 'note' => __('Gəlir deyil. Sistem sütununda — kassadan banka köçürmələr (müqayisə üçün): kassada yığılan nağd pul banka çox vaxt «təsisçi borcu» kimi daxil edilir.')],
        ];
    }

    /**
     * Xərc qrupları üzrə: kassa (08 fayl) ↔ rəsmi bank ödənişi (Cash Flow) ↔ MZ (hesablanmış).
     *
     * @return list<object>
     */
    private function expenses(ReportFilters $filters, Collection $pl, Collection $flows): array
    {
        $cash = [];
        $cashCategories = [];
        foreach ($this->pnl->cashByCategory($filters, 'year', 'expense') as $category => $values) {
            $group = self::cashGroup($category);
            $cash[$group] = ($cash[$group] ?? 0.0) + array_sum($values);
            $cashCategories[$group][] = $category;
        }
        $bank = $flows->where('section', 'payment')->whereNotNull('pnl_group')
            ->groupBy('pnl_group')->map(fn ($g) => (float) $g->sum('amount'))->all();
        $mz = [];
        foreach ($pl->where('section', 'expense') as $line) {
            $group = self::mzGroup((string) $line->tax_code, (string) $line->name);
            if ($group !== null) {
                $mz[$group] = ($mz[$group] ?? 0.0) + (float) $line->amount;
            }
        }

        $groups = array_unique(array_merge(array_keys($cash), array_keys($bank), array_keys($mz)));
        $rows = [];
        foreach ($groups as $group) {
            $c = $cash[$group] ?? 0.0;
            $b = $bank[$group] ?? 0.0;
            $status = match (true) {
                abs($b) > 0.005 && abs($c) < 0.005 => self::STATUS_ADDED,
                abs($b) > 0.005 && abs($c) > 0.005 => self::STATUS_BOTH,
                abs($c) > 0.005 => self::STATUS_CASH_ONLY,
                default => self::STATUS_INFO,
            };
            $rows[] = (object) [
                'group' => $group,
                'label' => $group === 'Amortizasiya' ? __('Amortizasiya') : FlowClassifier::groupLabel($group),
                'cash' => $c, 'bank' => $b, 'mz' => $mz[$group] ?? 0.0,
                'system_after' => $c + $b,
                'status' => $status,
                'categories' => $cashCategories[$group] ?? [],
            ];
        }
        usort($rows, fn ($x, $y) => ($y->system_after + $y->mz) <=> ($x->system_after + $x->mz));

        return $rows;
    }

    /**
     * @return list<object>
     */
    private function purchases(int $year, Collection $pl, Collection $flows): array
    {
        $system = (float) DB::table('purchase_lines')->whereBetween('period', [$year.'-01', $year.'-12'])->sum('total_cost');
        $bankGoods = (float) $flows->where('section', 'payment')->where('type', 'cogs')->sum('amount');
        $mzGoods = (float) $pl->where('section', 'expense')->filter(fn ($l) => str_starts_with((string) $l->tax_code, '221.4'))->sum('amount');
        $mzExport = (float) $pl->where('section', 'expense')->filter(fn ($l) => ProfitLossService::isExportLine($l))->sum('amount');

        return [
            (object) ['label' => __('İdxal alışları (03 faylı, maya ilə)'), 'amount' => $system, 'note' => __('Sistemin maya dəyəri bu alışlardan :method üsulu ilə hesablanır.', ['method' => CostingService::methodLabel()])],
            (object) ['label' => __('Mal / gömrük ödənişləri (bank: ELLIS, YGB)'), 'amount' => $bankGoods, 'note' => __('Ödəniş tarixi alış tarixindən fərqlənə bilər; re-eksport malları da buradadır.')],
            (object) ['label' => __('MZ: satılmış malın dəyəri (daxili)'), 'amount' => $mzGoods, 'note' => __('Rəsmi uçotda daxili satışın mayası (221.4).')],
            (object) ['label' => __('MZ: re-eksportla bağlı xərclər'), 'amount' => $mzExport, 'note' => __('232.1 — re-eksport malının mayası və xərcləri; P&L-də re-eksport blokundadır.')],
        ];
    }

    /**
     * Ad oxşarlığı ilə ən yaxın müştəri (şirkət formaları və «oğlu/qızı» nəzərə alınmır).
     *
     * @return array{0: object|null, 1: float}
     */
    public static function bestCustomer(string $name, Collection $customers): array
    {
        $strip = fn (string $v) => trim(preg_replace('/\b(mmc|asc|qsc|ooo|ltd|llc|oglu|ogly|qizi|kizi|servis|service|company)\b/u', ' ', NameNormalizer::normalize($v)) ?? '');
        $target = $strip($name);
        $targetTokens = array_filter(explode(' ', $target), fn ($t) => strlen($t) >= 4);
        if ($target === '' || $targetTokens === []) {
            return [null, 0.0];
        }
        // «electro» ≈ «elektro»: uzun sözlərdə bir hərf fərqi eyni söz sayılır
        $same = fn (string $a, string $b) => $a === $b || (min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= 1);
        $best = [null, 0.0];
        foreach ($customers as $customer) {
            foreach (array_filter(array_merge([$customer->name], explode(';', (string) $customer->other_names))) as $candidate) {
                $value = $strip($candidate);
                $tokens = array_values(array_filter(explode(' ', $value), fn ($t) => strlen($t) >= 4));
                if ($tokens === []) {
                    continue;
                }
                $common = count(array_filter($targetTokens, fn ($t) => array_filter($tokens, fn ($c) => $same($t, $c)) !== []));
                // tək söz (məs. yalnız soyad) kifayət deyil — ən azı iki söz üst-üstə düşməlidir
                if ($common < min(2, count($targetTokens), count($tokens))) {
                    continue;
                }
                $overlap = ($common / count($targetTokens) + $common / count($tokens)) / 2;
                $score = count($targetTokens) === 1 ? NameNormalizer::similarity($target, $value) : max($overlap, NameNormalizer::similarity($target, $value));
                if ($score > $best[1]) {
                    $best = [$customer, $score];
                }
            }
        }

        return $best[1] >= 0.6 ? $best : [null, $best[1]];
    }

    /**
     * Kassa kateqoriyasını rəsmi xərc qrupuna bağlayır (müqayisə üçün).
     */
    public static function cashGroup(string $category): string
    {
        $key = NameNormalizer::normalize($category);

        return match (true) {
            (bool) preg_match('/emek haqqi|bonus|premiya/', $key) => 'Əmək haqqı',
            (bool) preg_match('/icare/', $key) => 'İcarə',
            (bool) preg_match('/neqliyyat|avtokar|ford|logist/', $key) => 'Nəqliyyat və logistika',
            (bool) preg_match('/yanacaq/', $key) => 'Yanacaq',
            (bool) preg_match('/bank|komissiya/', $key) => 'Bank xidməti',
            (bool) preg_match('/marketinq|reklam/', $key) => 'Reklam və marketinq',
            (bool) preg_match('/sayt|domen|\bit\b/', $key) => 'IT və proqram təminatı',
            (bool) preg_match('/resmi|huquqi|audit|notarius|muhasib/', $key) => 'Audit, mühasibat və məsləhət',
            (bool) preg_match('/ezamiyye/', $key) => 'Ezamiyyə',
            (bool) preg_match('/ofis|kommunal/', $key) => 'Ofis və təsərrüfat',
            (bool) preg_match('/rabite|telefon|internet/', $key) => 'Rabitə',
            default => 'Sair xərclər',
        };
    }

    /**
     * MZ xərc sətrini (vergi bəyannaməsi kodu) rəsmi xərc qrupuna bağlayır. Malın dəyəri və re-eksport xərci daxil deyil (null).
     */
    public static function mzGroup(string $code, string $name): ?string
    {
        if (str_starts_with($code, '221.4') || ProfitLossService::isExportLine((object) ['tax_code' => $code, 'name' => $name])) {
            return null;
        }

        return match (true) {
            str_starts_with($code, '221.1') => 'Əmək haqqı',
            str_starts_with($code, '221.2') => 'Sosial sığorta (DSMF)',
            str_starts_with($code, '221.5') => 'İcarə',
            str_starts_with($code, '221.6') => 'Ezamiyyə',
            str_starts_with($code, '221.9') => 'Yanacaq',
            str_starts_with($code, '221.11') => 'Rabitə',
            str_starts_with($code, '221.13') => 'Bank xidməti',
            str_starts_with($code, '221.14') => 'Reklam və marketinq',
            str_starts_with($code, '229') => 'Nəqliyyat və logistika',
            str_starts_with($code, '230') => 'Sığorta',
            str_starts_with($code, '232') => 'Audit, mühasibat və məsləhət',
            str_starts_with($code, '227'), str_starts_with($code, '228') => 'Amortizasiya',
            default => 'Sair xərclər',
        };
    }
}
