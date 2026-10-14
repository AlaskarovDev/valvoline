<?php

namespace App\Services;

use App\Reports\ReportFilters;
use App\Support\Fmt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hesablar planına hərəkətlərin bağlanması.
 *
 * İdarəetmə (sistem importları 01–08), tarixə görə:
 *   205 Mallar — anbar qalığı mayası ilə (FIFO və ya hərəkətli orta);   211 / 542 — müştəri borcları / alınmış avanslar;
 *   221 Kassa — kassa qalığı;   601 / 602 — satış / qaytarma;   611 — kassa mədaxili (sair gəlir);
 *   701 — satılan malın və hədiyyə / daxili istifadənin mayası;   711 — reklam, nəqliyyat, yanacaq;
 *   721 — qalan kassa xərcləri;   751 — bank komissiyası;   801 — brüt mənfəət;   341 — xalis mənfəət;   344 — təsisçiyə ödənişlər.
 * Rəsmi (mühasibat faylı): balans hesabları köhnə nömrələrdən (AccountingController), gəlir / xərc hesabları MZ maddələrindən.
 */
class ChartLedgerService
{
    /** Gəliri azaldan (kontr-gəlir) hesablar: qaytarma və güzəştlər — cəmlərdə çıxılır. */
    public const CONTRA_INCOME = ['602', '603'];

    /** Bölmə / qrup cəmlərində çıxılan hesablar: kontr-gəlir və kapitalı azaldan təsisçiyə ödənişlər (344). */
    public const CONTRA = ['602', '603', '344'];

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
        // idarəetmə mənfəəti yalnız importlardan: re-eksport və rəsmi əlavə gəlir (mühasibat faylı) çıxılır
        $add('341', __('Xalis mənfəət (idarəetmə P&L)'), $sum('net_profit') - $sum('export_result') - $sum('official_extra'), $detail('net_profit'));
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
     * İdarəetmə balansı: management() + 343 keçmiş illərin bölüşdürülməmiş mənfəəti
     * (əvvəlki illərin idarəetmə xalis mənfəəti − təsisçiyə ödənişlər). Süni balanslaşdırıcı sətir yoxdur —
     * aktiv ilə passiv fərqi importlarda olmayan qalıqları (bank, malsatan borcları, təsisçi qoyuluşu) göstərir.
     *
     * @return array<string, list<array{label: string, amount: float, url: string|null}>>
     */
    public function managementBalance(Carbon $date): array
    {
        $out = $this->management($date);
        $first = collect([
            DB::table('sales_lines')->min('date'), DB::table('purchase_lines')->min('date'),
            DB::table('cash_transactions')->min('date'), DB::table('payments')->min('date'),
        ])->filter()->min();
        $previousEnd = $date->copy()->startOfYear()->subDay()->endOfDay();
        if ($first !== null && Carbon::parse($first)->lte($previousEnd)) {
            $filters = new ReportFilters(Carbon::parse($first)->startOfDay(), $previousEnd, 'custom', direction: 'VALVO');
            $lines = $this->pnl->build($filters, 'year', false)['lines'];
            $range = ['from' => Carbon::parse($first)->toDateString(), 'to' => $previousEnd->toDateString(), 'gran' => 'year'];
            foreach ($lines['net_profit'] ?? [] as $year => $profit) {
                $profit = (float) $profit - (float) ($lines['export_result'][$year] ?? 0) - (float) ($lines['official_extra'][$year] ?? 0);
                if (abs($profit) > 0.005) {
                    $out['343'][] = ['label' => __(':year ilin xalis mənfəəti', ['year' => $year]), 'amount' => (float) $profit, 'url' => route('pnl.index', $range)];
                }
            }
            foreach ($lines['founder'] ?? [] as $year => $founder) {
                if (abs((float) $founder) > 0.005) {
                    $out['343'][] = ['label' => __(':year ilin təsisçiyə ödənişləri', ['year' => $year]), 'amount' => -(float) $founder, 'url' => route('pnl.index', $range)];
                }
            }
        }

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
        $rows = $this->customerRows($date);
        $positive = $rows->where('balance', '>', 0.005);
        $negative = $rows->where('balance', '<', -0.005);

        return [(float) $positive->sum('balance'), (float) -$negative->sum('balance'), $positive->count(), $negative->count()];
    }

    /**
     * Müştəri üzrə tarixə qədər satış, qaytarma, ödəniş və qalıq.
     *
     * @return Collection<int, object>
     */
    private function customerRows(string $date): Collection
    {
        $customers = DB::table('customers')->where('type', 'customer')->get(['id', 'name'])->keyBy('id');
        $data = [];
        foreach ([
            'sales' => DB::table('sales_lines')->where('op_type', 'sale')->where('status', 'ok')->where('date', '<=', $date)->groupBy('customer_id')->selectRaw('customer_id, SUM(net_amount) AS amount')->get(),
            'returns' => DB::table('sales_returns')->where('op_type', 'customer')->where('date', '<=', $date)->groupBy('customer_id')->selectRaw('customer_id, SUM(net_amount) AS amount')->get(),
            'payments' => DB::table('payments')->where('date', '<=', $date)->groupBy('customer_id')->selectRaw('customer_id, SUM(total) AS amount')->get(),
        ] as $key => $rows) {
            foreach ($rows as $row) {
                if ($row->customer_id !== null && isset($customers[$row->customer_id])) {
                    $data[$row->customer_id][$key] = (float) $row->amount;
                }
            }
        }

        return collect($data)->map(fn ($v, $id) => (object) [
            'id' => $id,
            'name' => $customers[$id]->name,
            'sales' => $v['sales'] ?? 0.0,
            'returns' => $v['returns'] ?? 0.0,
            'payments' => $v['payments'] ?? 0.0,
            'balance' => ($v['sales'] ?? 0.0) - ($v['returns'] ?? 0.0) - ($v['payments'] ?? 0.0),
        ])->values();
    }

    /**
     * Balans sətrinin detalı (popup): mallar, müştərilər, kassa ayları, mənfəətin hesablanması.
     *
     * @return array{title: string, columns: list<string>, numeric: list<int>, rows: list<list<mixed>>, links: list<string|null>, foot: list<mixed>|null}|null
     */
    public function detail(string $code, Carbon $date): ?array
    {
        $day = $date->toDateString();
        switch ($code) {
            case '205':
                $rows = $this->stock->balances(new ReportFilters($date->copy()->startOfYear(), $date), $date->copy()->endOfDay())
                    ->where('qty', '>', 0)->sortByDesc('value')->values();

                return [
                    'title' => __('Mallar üzrə qalıq'), 'columns' => [__('Mal'), __('Miqdar'), __('Maya'), __('Dəyər')], 'numeric' => [1, 2, 3],
                    'rows' => $rows->map(fn ($p) => [trim($p->code.' '.$p->name), Fmt::qty($p->qty), (float) $p->avg_cost, (float) $p->value])->all(),
                    'links' => $rows->map(fn ($p) => route('movements.product', $p->id))->all(),
                    'foot' => [__('Cəmi'), Fmt::qty($rows->sum('qty')), '', (float) $rows->sum('value')],
                ];
            case '211':
            case '542':
                $rows = $this->customerRows($day)->filter(fn ($r) => $code === '211' ? $r->balance > 0.005 : $r->balance < -0.005)
                    ->sortBy(fn ($r) => $code === '211' ? -$r->balance : $r->balance)->values();

                return [
                    'title' => $code === '211' ? __('Borclu müştərilər') : __('Avanslı müştərilər'),
                    'columns' => [__('Müştəri'), __('Satış'), __('Qaytarma'), __('Ödəniş'), __('Qalıq')], 'numeric' => [1, 2, 3, 4],
                    'rows' => $rows->map(fn ($r) => [$r->name, $r->sales, $r->returns, $r->payments, $code === '211' ? $r->balance : -$r->balance])->all(),
                    'links' => $rows->map(fn ($r) => route('customers.show', $r->id))->all(),
                    'foot' => [__('Cəmi'), (float) $rows->sum('sales'), (float) $rows->sum('returns'), (float) $rows->sum('payments'), (float) abs($rows->sum('balance'))],
                ];
            case '221':
                $months = DB::table('cash_transactions')->where('date', '<=', $day)->groupBy('period')->orderBy('period')
                    ->selectRaw('period, SUM(income) AS income, SUM(expense) AS expense')->get();
                $running = 0.0;
                $rows = $months->map(function ($m) use (&$running) {
                    $running += (float) $m->income - (float) $m->expense;

                    return [Fmt::period($m->period), (float) $m->income, (float) $m->expense, $running];
                })->reverse()->values();

                return [
                    'title' => __('Kassa — aylar üzrə'), 'columns' => [__('Ay'), __('Mədaxil'), __('Məxaric'), __('Ay sonuna qalıq')], 'numeric' => [1, 2, 3],
                    'rows' => $rows->all(), 'links' => [],
                    'foot' => [__('Cəmi'), (float) $months->sum('income'), (float) $months->sum('expense'), $running],
                ];
            case '341':
                $filters = new ReportFilters($date->copy()->startOfYear(), $date->copy()->endOfDay(), 'custom', direction: 'VALVO');
                $report = $this->pnl->build($filters, 'year', false);
                $sum = fn (string $key) => (float) array_sum($report['lines'][$key] ?? []);
                $rows = [[__('Satışlar'), $sum('sales')], [__('− Qaytarma'), -$sum('returns')], [__('− Satılan malın mayası'), -$sum('sale_cogs')],
                    [__('− Hədiyyə / daxili istifadə mayası'), -$sum('gift_cogs')], [__('= Brüt mənfəət'), $sum('gross_profit')]];
                foreach ($report['other_income'] as $category => $values) {
                    $rows[] = ['+ '.$category, (float) array_sum($values)];
                }
                foreach ($report['expenses'] as $category => $values) {
                    $rows[] = ['− '.$category, -(float) array_sum($values)];
                }

                return [
                    'title' => __('Xalis mənfəətin hesablanması (:from – :to)', ['from' => Fmt::date($filters->from), 'to' => Fmt::date($filters->to)]),
                    'columns' => [__('Göstərici'), __('Məbləğ')], 'numeric' => [1], 'rows' => $rows, 'links' => [],
                    'foot' => [__('= Xalis mənfəət'), $sum('gross_profit') + $sum('other_income') - $sum('expenses')],
                ];
            default:
                return null;
        }
    }
}
