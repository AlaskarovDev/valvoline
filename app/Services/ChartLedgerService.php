<?php

namespace App\Services;

use App\Reports\ReportFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hesablar planına hərəkətlərin bağlanması.
 *
 * İdarəetmə (sistem importları 01–08), tarixə görə:
 *   205 Mallar — anbar qalığı × hərəkətli orta maya;   211 / 542 — müştəri borcları / alınmış avanslar;
 *   221 Kassa — kassa qalığı;   601 / 602 — satış / qaytarma;   611 — kassa mədaxili (sair gəlir);
 *   701 — satılan malın və hədiyyə / daxili istifadənin mayası;   711 — reklam, nəqliyyat, yanacaq;
 *   721 — qalan kassa xərcləri;   751 — bank komissiyası;   801 — brüt mənfəət;   341 — xalis mənfəət;   344 — təsisçiyə ödənişlər.
 * Rəsmi (mühasibat faylı): balans hesabları köhnə nömrələrdən (AccountingController), gəlir / xərc hesabları MZ maddələrindən.
 */
class ChartLedgerService
{
    /** Gəliri azaldan (kontr-gəlir) hesablar: qaytarma və güzəştlər — cəmlərdə çıxılır. */
    public const CONTRA_INCOME = ['602', '603'];

    public function __construct(private ProfitLossService $pnl, private StockService $stock) {}

    /**
     * İdarəetmə tərəfi: hesab → komponentlər (ad, məbləğ, keçid).
     *
     * @return array<string, list<array{label: string, amount: float, url: string|null}>>
     */
    public function management(Carbon $date): array
    {
        $date = $date->copy()->endOfDay();
        $start = $date->copy()->startOfYear();
        $filters = new ReportFilters($start, $date, 'custom', direction: 'VALVO');
        $range = ['from' => $start->toDateString(), 'to' => $date->toDateString()];
        $report = $this->pnl->build($filters, 'year', false);
        $sum = fn (string $key) => (float) array_sum($report['lines'][$key] ?? []);
        $detail = fn (string $line, ?string $child = null) => route('pnl.detail', $range + array_filter(['gran' => 'year', 'line' => $line, 'col' => 'total', 'child' => $child]));
        $out = [];
        $add = function (string $code, string $label, float $amount, ?string $url = null) use (&$out) {
            if (abs($amount) > 0.005) {
                $out[$code][] = ['label' => $label, 'amount' => $amount, 'url' => $url];
            }
        };

        // gəlir və xərclər (ilin əvvəlindən tarixə qədər)
        $add('601', __('Satışlar (04 faylı)'), $sum('sales'), $detail('sales'));
        $add('602', __('Qaytarmalar (05 faylı)'), $sum('returns'), $detail('returns'));
        foreach ($report['other_income'] as $category => $values) {
            $add('611', (string) $category, (float) array_sum($values), $detail('other_income', (string) $category));
        }
        $add('701', __('Satılan malın mayası'), $sum('sale_cogs'), $detail('sale_cogs'));
        $add('701', __('Hədiyyə / daxili istifadə mayası'), $sum('gift_cogs'), $detail('gift_cogs'));
        foreach ($report['expenses'] as $category => $values) {
            $add(self::expenseAccount(ReconciliationService::cashGroup((string) $category)), (string) $category, (float) array_sum($values), $detail('expenses', (string) $category));
        }
        $add('801', __('Brüt mənfəət'), $sum('gross_profit'), $detail('gross_profit'));
        $add('341', __('Xalis mənfəət (idarəetmə P&L)'), $sum('net_profit'), $detail('net_profit'));
        foreach ($report['founder'] as $category => $values) {
            $add('344', (string) $category, (float) array_sum($values), $detail('founder', (string) $category));
        }

        // balans hesabları (tarixə qalıq)
        $stock = $this->stock->balances(new ReportFilters($start, $date), $date);
        $add('205', __('Anbar qalığı × orta maya (:n mal)', ['n' => $stock->where('qty', '>', 0)->count()]),
            (float) $stock->where('qty', '>', 0)->sum('value'), route('stock.index', ['asof' => $date->toDateString()]));

        [$receivable, $advances, $debtors, $creditors] = $this->customerBalances($date->toDateString());
        $add('211', __('Müştəri borcları (:n müştəri)', ['n' => $debtors]), $receivable, route('debts.index'));
        $add('542', __('Müştərilərin avansları / artıq ödənişləri (:n müştəri)', ['n' => $creditors]), $advances, route('debts.index', ['tab' => 'advances']));

        $add('221', __('Kassa qalığı (08 faylı)'), (float) DB::table('cash_transactions')->where('date', '<=', $date->toDateString())->sum(DB::raw('income - expense')), route('cash.index'));

        return $out;
    }

    /**
     * Rəsmi gəlir / xərc hesabları: MZ maddələri hesablara bağlanır (vergi bəyannaməsi kodu üzrə).
     *
     * @return array<string, list<array{label: string, amount: float, url: string|null}>>
     */
    public function officialTurnover(int $year): array
    {
        $out = [];
        $url = route('accounting.pl', ['year' => $year]);
        foreach (DB::table('acc_pl_lines')->where('year', $year)->whereIn('section', ['income', 'expense'])->orderBy('row_no')->get() as $line) {
            $code = self::mzAccount($line);
            if (abs((float) $line->amount) > 0.005) {
                $out[$code][] = ['label' => trim(($line->tax_code ? $line->tax_code.' · ' : '').$line->name), 'amount' => (float) $line->amount, 'url' => $url];
            }
        }
        $total = fn (string $code) => array_sum(array_column($out[$code] ?? [], 'amount'));
        $gross = $total('601') - $total('602') - $total('701');
        if (abs($gross) > 0.005) {
            $out['801'][] = ['label' => __('601 − 701 (MZ)'), 'amount' => $gross, 'url' => $url];
        }

        return $out;
    }

    /**
     * Kassa xərc qrupu → kommersiya (711), maliyyə (751) və ya inzibati (721) xərc hesabı.
     */
    public static function expenseAccount(string $group): string
    {
        return match ($group) {
            'Reklam və marketinq', 'Nəqliyyat və logistika', 'Yanacaq' => '711',
            'Bank xidməti', 'Maliyyə xərcləri (kurs fərqi)' => '751',
            default => '721',
        };
    }

    /**
     * MZ maddəsi (vergi bəyannaməsi kodu) → hesab.
     */
    public static function mzAccount(object $line): string
    {
        $code = (string) $line->tax_code;
        if ($line->section === 'income') {
            return match (true) {
                str_starts_with($code, '200'), str_starts_with($code, '217') => '601',
                str_starts_with($code, '214'), str_starts_with($code, '219') => '631',
                default => '611',
            };
        }

        return match (true) {
            str_starts_with($code, '221.4') || ProfitLossService::isExportLine($line) => '701',
            str_starts_with($code, '221.14'), str_starts_with($code, '221.9'), str_starts_with($code, '229') => '711',
            str_starts_with($code, '221.13') => '751',
            default => '721',
        };
    }

    /**
     * Tarixə müştəri qalıqları: satış − müştəri qaytarması − ödəniş (yalnız «müştəri» tipli).
     *
     * @return array{0: float, 1: float, 2: int, 3: int} borclar, avanslar (müsbət), borclu sayı, avanslı sayı
     */
    private function customerBalances(string $date): array
    {
        $customers = DB::table('customers')->where('type', 'customer')->pluck('id')->flip();
        $balance = [];
        foreach ([
            [DB::table('sales_lines')->where('op_type', 'sale')->where('status', 'ok')->where('date', '<=', $date)->groupBy('customer_id')->selectRaw('customer_id, SUM(net_amount) AS amount')->get(), 1],
            [DB::table('sales_returns')->where('op_type', 'customer')->where('date', '<=', $date)->groupBy('customer_id')->selectRaw('customer_id, SUM(net_amount) AS amount')->get(), -1],
            [DB::table('payments')->where('date', '<=', $date)->groupBy('customer_id')->selectRaw('customer_id, SUM(total) AS amount')->get(), -1],
        ] as [$rows, $sign]) {
            foreach ($rows as $row) {
                if ($row->customer_id !== null && isset($customers[$row->customer_id])) {
                    $balance[$row->customer_id] = ($balance[$row->customer_id] ?? 0.0) + $sign * (float) $row->amount;
                }
            }
        }
        $positive = array_filter($balance, fn ($v) => $v > 0.005);
        $negative = array_filter($balance, fn ($v) => $v < -0.005);

        return [(float) array_sum($positive), (float) -array_sum($negative), count($positive), count($negative)];
    }
}
