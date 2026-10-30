<?php

namespace App\Services;

use App\Support\AppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plan–fakt və bonus (ay üzrə, satış nümayəndəsi):
 *  - fakt satış = satış − müştəri qaytarması; inkasso = daxil olan ödənişlər; brüt mənfəət = satış sətirlərinin mənfəəti;
 *  - icra % = fakt / hədəf; ay bitməyibsə proqnoz = fakt / keçən gün × aydakı gün;
 *  - bonus: icra faizinin çatdığı ən yüksək pillənin faizi bonus bazasına (satış, inkasso və ya brüt mənfəət) tətbiq olunur.
 */
class RepPerformanceService
{
    public const SETTING = 'bonus_rules';

    /**
     * @return array{base: string, measure: string, tiers: list<array{from: float, rate: float}>}
     */
    public static function rules(): array
    {
        $saved = json_decode((string) AppSettings::get(self::SETTING), true);
        $default = ['base' => 'sales', 'measure' => 'sales', 'tiers' => [['from' => 80, 'rate' => 0.5], ['from' => 100, 'rate' => 1.0], ['from' => 120, 'rate' => 1.5]]];
        if (! is_array($saved)) {
            return $default;
        }
        $tiers = collect($saved['tiers'] ?? [])->filter(fn ($t) => is_numeric($t['from'] ?? null) && is_numeric($t['rate'] ?? null))
            ->map(fn ($t) => ['from' => (float) $t['from'], 'rate' => (float) $t['rate']])->sortBy('from')->values()->all();

        return [
            'base' => in_array($saved['base'] ?? null, array_keys(self::baseLabels()), true) ? $saved['base'] : 'sales',
            'measure' => in_array($saved['measure'] ?? null, ['sales', 'collections'], true) ? $saved['measure'] : 'sales',
            'tiers' => $tiers,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function baseLabels(): array
    {
        return ['sales' => __('Fakt satış'), 'collections' => __('İnkasso'), 'profit' => __('Brüt mənfəət')];
    }

    /**
     * @return Collection<int, object>
     */
    public function month(string $period, ?int $managerId = null): Collection
    {
        $start = Carbon::parse($period.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $today = now()->startOfDay();
        $elapsed = $today->lt($start) ? 0 : ($today->gt($end) ? $end->day : $today->day);
        $range = [$start->toDateString(), $end->toDateString()];
        $sum = fn ($table, $column, $where) => DB::table($table)->whereBetween('date', $range)->whereNotNull('manager_id')->where($where)
            ->groupBy('manager_id')->selectRaw("manager_id, SUM($column) AS v")->pluck('v', 'manager_id');
        $sales = $sum('sales_lines', 'net_amount', fn ($q) => $q->where('op_type', 'sale'));
        $profit = $sum('sales_lines', 'gross_profit', fn ($q) => $q->where('op_type', 'sale'));
        $returns = $sum('sales_returns', 'net_amount', fn ($q) => $q->where('op_type', 'customer'));
        $collections = $sum('payments', 'total', fn ($q) => $q->where('total', '>', 0));
        $targets = DB::table('rep_targets')->where('period', $period)->get()->keyBy('manager_id');
        $rules = self::rules();

        return DB::table('managers')->when($managerId !== null, fn ($q) => $q->where('id', $managerId))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $sales->keys()->all()))->orderBy('name')->get(['id', 'code', 'name', 'monthly_target'])
            ->map(function ($m) use ($sales, $profit, $returns, $collections, $targets, $rules, $elapsed, $end) {
                $t = $targets[$m->id] ?? null;
                $r = (object) [
                    'id' => $m->id, 'code' => $m->code, 'name' => $m->name,
                    'sales_target' => $t?->sales_target !== null ? (float) $t->sales_target : ($m->monthly_target !== null ? (float) $m->monthly_target : null),
                    'collection_target' => $t?->collection_target !== null ? (float) $t->collection_target : null,
                    'sales' => round((float) ($sales[$m->id] ?? 0) - (float) ($returns[$m->id] ?? 0), 2),
                    'collections' => round((float) ($collections[$m->id] ?? 0), 2),
                    'profit' => round((float) ($profit[$m->id] ?? 0), 2),
                ];
                $r->sales_pct = $r->sales_target ? round($r->sales / $r->sales_target * 100, 1) : null;
                $r->collection_pct = $r->collection_target ? round($r->collections / $r->collection_target * 100, 1) : null;
                $r->forecast = $elapsed > 0 && $elapsed < $end->day ? round($r->sales / $elapsed * $end->day, 2) : $r->sales;
                $r->forecast_pct = $r->sales_target ? round($r->forecast / $r->sales_target * 100, 1) : null;
                $achieved = $rules['measure'] === 'collections' ? $r->collection_pct : $r->sales_pct;
                $tier = $achieved === null ? null : collect($rules['tiers'])->filter(fn ($x) => $achieved + 1e-9 >= $x['from'])->last();
                $r->bonus_rate = $tier['rate'] ?? 0.0;
                $base = match ($rules['base']) {
                    'collections' => $r->collections, 'profit' => $r->profit, default => $r->sales
                };
                $r->bonus = round(max(0.0, $base) * $r->bonus_rate / 100, 2);

                return $r;
            });
    }

    public function saveTarget(int $managerId, string $period, ?float $sales, ?float $collections): void
    {
        DB::table('rep_targets')->updateOrInsert(['manager_id' => $managerId, 'period' => $period],
            ['sales_target' => $sales, 'collection_target' => $collections, 'updated_at' => now(), 'created_at' => now()]);
    }
}
