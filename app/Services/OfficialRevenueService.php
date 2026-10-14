<?php

namespace App\Services;

use App\Reports\ReportFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rəsmi gəlirlər — mühasibat Cash Flow-dakı müştəri daxilolmaları (bank) və onların sistemlə tutuşdurulması.
 *
 * Satış gəliri sistemdə satış faylından hesablanır; bank daxilolması isə həmin satışların (və ya keçmiş borcun) pulu ola bilər.
 * Ona görə daxilolma P&L-ə yalnız ödəyicinin satışı sistemdə tapılmayanda əlavə olunur (istifadəçi qərarı dəyişə bilər).
 * Dövrün əvvəlinə qalıq keçmiş illərin pulu olduğu üçün heç vaxt gəlir sayılmır.
 */
class OfficialRevenueService
{
    public const INCLUDED = 'included';

    public const EXTRA = 'extra';

    public const NOT_REVENUE = 'not_revenue';

    public const PENDING = 'pending';

    public const DECISIONS = [self::INCLUDED, self::EXTRA, self::PENDING, self::NOT_REVENUE];

    /** @var array<int, Collection<int, object>> il => ödəyicilər (sorğu daxilində təkrar hesablanmasın) */
    private array $payersCache = [];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::INCLUDED => __('Sistemdə satış kimi var — təkrar sayılmır'),
            self::EXTRA => __('Sistemdə olmayan satış — P&L-ə əlavə'),
            self::NOT_REVENUE => __('Gəlir deyil'),
            self::PENDING => __('Yoxlanılmalı — P&L-ə düşmür'),
        ];
    }

    /**
     * İl üzrə ödəyicilər: aylıq məbləğlər, sistemdəki müştəri, satış / ödəniş və qərar.
     *
     * @return Collection<int, object>
     */
    public function payers(int $year): Collection
    {
        return $this->payersCache[$year] ??= $this->computePayers($year);
    }

    /**
     * @return Collection<int, object>
     */
    private function computePayers(int $year): Collection
    {
        $rows = $this->receiptLines($year.'-01', $year.'-12');
        if ($rows->isEmpty()) {
            return collect();
        }
        $customers = DB::table('customers')->where('type', 'customer')->get(['id', 'name', 'other_names']);
        $sales = DB::table('sales_lines')->where('op_type', 'sale')->where('status', 'ok')->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(CASE WHEN period BETWEEN ? AND ? THEN net_amount ELSE 0 END) AS year_sales, SUM(CASE WHEN period < ? THEN net_amount ELSE 0 END) AS earlier_sales', [$year.'-01', $year.'-12', $year.'-01'])
            ->get()->keyBy('customer_id');
        $paid = DB::table('payments')->whereBetween('period', [$year.'-01', $year.'-12'])->whereNotNull('customer_id')
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(total) AS total')->pluck('total', 'customer_id');
        $decisions = DB::table('revenue_decisions')->get()->keyBy('normalized');

        return $rows->groupBy('normalized')->map(function (Collection $group, string $normalized) use ($customers, $sales, $paid, $decisions) {
            $name = (string) $group->first()->counterparty;
            [$customer, $score] = ReconciliationService::bestCustomer($name, $customers);
            $s = $customer ? ($sales[$customer->id] ?? null) : null;
            $yearSales = (float) ($s->year_sales ?? 0);
            $earlierSales = (float) ($s->earlier_sales ?? 0);
            $auto = match (true) {
                $customer !== null && $yearSales > 0 => [self::INCLUDED, __('Müştərinin bu il satışları sistemdədir — ödəniş həmin satışların pulu.')],
                $customer !== null && $earlierSales > 0 => [self::INCLUDED, __('Müştərinin əvvəlki illərdə satışı var — ödəniş keçmiş borcun pulu ola bilər, gəlir artıq sayılıb.')],
                $customer !== null => [self::EXTRA, __('Müştəri tapıldı, amma sistemdə satışı yoxdur — rəsmi gəlir kimi P&L-ə əlavə olunur.')],
                default => [self::EXTRA, __('Ödəyici sistemdə tapılmadı — rəsmi gəlir kimi P&L-ə əlavə olunur. Satış başqa adla yazılıbsa «Sistemdə satış kimi var» seçin.')],
            };
            $manual = $decisions[$normalized] ?? null;

            return (object) [
                'normalized' => $normalized,
                'counterparty' => $name,
                'months' => $group->groupBy('period')->map(fn ($g) => (float) $g->sum('amount'))->all(),
                'total' => (float) $group->sum('amount'),
                'customer' => $customer,
                'score' => $score,
                'year_sales' => $yearSales,
                'earlier_sales' => $earlierSales,
                'year_paid' => $customer ? (float) ($paid[$customer->id] ?? 0) : 0.0,
                'decision' => $manual->decision ?? $auto[0],
                'auto' => $auto[0],
                'reason' => $auto[1],
                'manual' => $manual !== null,
            ];
        })->sortByDesc('total')->values();
    }

    /**
     * P&L-ə əlavə olunan rəsmi gəlir («sistemdə olmayan satış» seçilmiş ödəyicilər), sütun üzrə.
     *
     * @param  'month'|'year'  $granularity
     * @return array<string, float>
     */
    public function extraByColumn(ReportFilters $filters, string $granularity = 'month'): array
    {
        $result = [];
        foreach ($this->matrix($filters, $granularity) as $row) {
            if ($row->decision === self::EXTRA) {
                foreach ($row->values as $column => $amount) {
                    $result[$column] = ($result[$column] ?? 0.0) + $amount;
                }
            }
        }

        return $result;
    }

    /**
     * Excel-in «Gəlirlər» bölməsi P&L sütunları üzrə: hər ödəyici (qərarı ilə), təsisçi və re-eksport alıcısı.
     *
     * @param  'month'|'year'  $granularity
     * @return list<object{label: string, kind: string, decision: string, values: array<string, float>}>
     */
    public function matrix(ReportFilters $filters, string $granularity = 'month'): array
    {
        if ($filters->direction !== null && ! in_array($filters->direction, ['all', 'VALVO'], true)) {
            return [];
        }
        [$from, $to] = [$filters->fromPeriod(), $filters->toPeriod()];
        $column = fn (string $period) => $granularity === 'year' ? substr($period, 0, 4) : $period;
        $rows = [];
        foreach (range((int) substr($from, 0, 4), (int) substr($to, 0, 4)) as $year) {
            foreach ($this->payers($year) as $payer) {
                $key = $payer->normalized.'|'.$payer->decision;
                $rows[$key] ??= (object) ['label' => $payer->counterparty, 'kind' => 'customer', 'decision' => $payer->decision, 'values' => []];
                foreach ($payer->months as $period => $amount) {
                    if ($period >= $from && $period <= $to) {
                        $rows[$key]->values[$column($period)] = ($rows[$key]->values[$column($period)] ?? 0.0) + $amount;
                    }
                }
            }
        }
        $classifier = new FlowClassifier;
        foreach (DB::table('bank_flow_lines')->where('section', 'income')->whereBetween('period', [$from, $to])->where('amount', '!=', 0)->orderBy('row_no')->get() as $f) {
            $kind = match (true) {
                self::isExportBuyer((string) $f->normalized) => 'export',
                $classifier->classify((string) $f->counterparty, 'income')['type'] === 'financing' => 'founder',
                default => null,
            };
            if ($kind !== null) {
                $key = $kind.'|'.$f->normalized;
                $rows[$key] ??= (object) ['label' => $f->counterparty, 'kind' => $kind, 'decision' => self::NOT_REVENUE, 'values' => []];
                $rows[$key]->values[$column($f->period)] = ($rows[$key]->values[$column($f->period)] ?? 0.0) + (float) $f->amount;
            }
        }

        return array_values(array_filter($rows, fn ($r) => $r->values !== []));
    }

    /**
     * Dövr üzrə rəsmi pul axını: açılış qalığı, daxilolmaların sistemlə münasibəti, ödənişlər, son qalıq.
     *
     * @return array<string, float|string|null>|null
     */
    public function cashSummary(ReportFilters $filters): ?array
    {
        [$from, $to] = [$filters->fromPeriod(), $filters->toPeriod()];
        $flows = DB::table('bank_flow_lines')->whereBetween('period', [$from, $to])->get();
        // yalnız qalıq təkrarı olan aylar (hərəkətsiz) — bu dövr üçün məlumat yoxdur
        if ($flows->whereIn('section', ['income', 'payment'])->where('amount', '!=', 0)->isEmpty()) {
            return null;
        }
        $classifier = new FlowClassifier;
        $income = $flows->where('section', 'income')->map(function ($f) use ($classifier) {
            $f->type = $classifier->classify($f->counterparty, 'income')['type'];

            return $f;
        });
        $decisionOf = [];
        foreach (range((int) substr($from, 0, 4), (int) substr($to, 0, 4)) as $year) {
            foreach ($this->payers($year) as $payer) {
                $decisionOf[$year.'|'.$payer->normalized] = $payer->decision;
            }
        }
        $customer = $income->where('type', 'revenue')->reject(fn ($f) => self::isExportBuyer($f->normalized));
        $by = fn (string $decision) => (float) $customer->filter(fn ($f) => ($decisionOf[substr($f->period, 0, 4).'|'.$f->normalized] ?? self::EXTRA) === $decision)->sum('amount');
        $opening = $flows->where('section', 'opening')->sortBy('period')->first();
        // faylda gələcək aylar əvvəlki qalığın təkrarıdır — son qalıq hərəkəti olan son ayındır
        $lastActive = $flows->whereIn('section', ['income', 'payment'])->where('amount', '!=', 0)->max('period');
        $closing = $flows->where('section', 'closing')->when($lastActive, fn ($c) => $c->where('period', '<=', $lastActive))->sortBy('period')->last();

        return [
            'opening' => $opening ? (float) $opening->amount : null,
            'opening_period' => $opening->period ?? null,
            'customer' => (float) $customer->sum('amount'),
            'included' => $by(self::INCLUDED),
            'extra' => $by(self::EXTRA),
            'pending' => $by(self::PENDING),
            'not_revenue' => $by(self::NOT_REVENUE),
            'export' => (float) $income->filter(fn ($f) => self::isExportBuyer($f->normalized))->sum('amount'),
            'founder' => (float) $income->where('type', 'financing')->sum('amount'),
            'other_income' => (float) $income->whereNotIn('type', ['revenue', 'financing'])->sum('amount'),
            'payments' => (float) $flows->where('section', 'payment')->sum('amount'),
            'fx' => (float) $flows->where('section', 'other')->sum('amount'),
            'closing' => $closing ? (float) $closing->amount : null,
            'closing_period' => $closing->period ?? null,
        ];
    }

    /**
     * Açılış qalıqlarının ardıcıllığı: hər ilin yanvar açılışı = əvvəlki ilin son qalığı; hesablar üzrə tərkib.
     *
     * @return list<object>
     */
    public function openings(): array
    {
        $result = [];
        foreach (DB::table('bank_flow_lines')->where('section', 'opening')->orderBy('period')->get()->groupBy('year') as $year => $lines) {
            $first = $lines->first();
            $previous = DB::table('bank_flow_lines')->where('year', (int) $year - 1)->where('section', 'closing')->where('amount', '!=', 0)->orderByDesc('period')->first();
            $result[] = (object) [
                'year' => (int) $year,
                'opening' => (float) $first->amount,
                'previous_closing' => $previous ? (float) $previous->amount : null,
                'accounts' => DB::table('bank_flow_balances')->where('period', ((int) $year - 1).'-12')->orderByDesc('amount')->pluck('amount', 'account')->map(fn ($v) => (float) $v)->all(),
                'system_cash' => (float) DB::table('cash_transactions')->where('direction', 'VALVO')->where('date', '<', $year.'-01-01')->sum(DB::raw('income - expense')),
            ];
        }

        return array_reverse($result);
    }

    public function decide(string $normalized, string $counterparty, ?string $decision, ?int $userId): void
    {
        $this->payersCache = [];
        if ($decision === null || $decision === 'auto') {
            DB::table('revenue_decisions')->where('normalized', $normalized)->delete();

            return;
        }
        DB::table('revenue_decisions')->updateOrInsert(['normalized' => $normalized], [
            'counterparty' => $counterparty, 'decision' => $decision, 'user_id' => $userId, 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    /**
     * Xarici (re-eksport) alıcı — onun ödənişləri re-eksport blokundadır, müştəri gəlirinə qarışmır.
     */
    public static function isExportBuyer(string $normalized): bool
    {
        return str_starts_with($normalized, 'ooo ');
    }

    /**
     * Müştəri daxilolmaları (satış gəliri növlü, re-eksport alıcısı xaric).
     *
     * @return Collection<int, object>
     */
    private function receiptLines(string $from, string $to): Collection
    {
        $classifier = new FlowClassifier;

        return DB::table('bank_flow_lines')->where('section', 'income')->whereBetween('period', [$from, $to])->where('amount', '!=', 0)
            ->orderBy('row_no')->get(['year', 'period', 'counterparty', 'normalized', 'amount'])
            ->filter(fn ($f) => ! self::isExportBuyer((string) $f->normalized) && $classifier->classify((string) $f->counterparty, 'income')['type'] === 'revenue')
            ->values();
    }
}
