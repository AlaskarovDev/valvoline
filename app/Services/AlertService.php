<?php

namespace App\Services;

use App\Models\User;
use App\Reports\ReportFilters;
use App\Support\Fmt;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bildirişlər: diqqət tələb edən vəziyyətlər (gecikmiş borc, limit aşımı, az / mənfi stok, vaxtı bitən partiya,
 * fakturalanmamış təhvil, köhnə ehtiyat nüsxəsi). Hər bildiriş yalnız hədəf səhifəyə girişi olan istifadəçiyə görünür;
 * menecer yalnız öz müştərilərini görür. Nəticə qısa müddət keşlənir, hər yazı əməliyyatı keşi təzələyir.
 */
class AlertService
{
    public const TTL = 600;

    private const VERSION_KEY = 'alerts:version';

    /**
     * @return list<array{key: string, level: string, title: string, detail: string, count: int, url: string}>
     */
    public function forUser(User $user, bool $fresh = false): array
    {
        $areas = array_values(array_filter(array_keys(Permissions::areas()), fn ($a) => $user->hasPermission($a)));
        $key = 'alerts:'.md5(json_encode([$areas, $user->restrictedManagerId(), app()->getLocale(), Cache::get(self::VERSION_KEY)]));
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TTL, fn () => $this->build($user));
    }

    /** Məlumat dəyişəndə bütün istifadəçilərin keşi köhnəlir. */
    public static function touch(): void
    {
        Cache::forever(self::VERSION_KEY, (string) microtime(true));
    }

    /**
     * @return list<array{key: string, level: string, title: string, detail: string, count: int, url: string}>
     */
    private function build(User $user): array
    {
        $manager = $user->restrictedManagerId();
        $can = fn (string $route) => Permissions::canRoute($user, $route);
        $alerts = [];
        $add = function (string $key, string $level, string $title, string $detail, int $count, string $url) use (&$alerts) {
            if ($count > 0) {
                $alerts[] = compact('key', 'level', 'title', 'detail', 'count', 'url');
            }
        };

        if ($can('debts.index')) {
            $customers = fn () => DB::table('customer_balances as b')->join('customers as c', 'c.id', '=', 'b.customer_id')
                ->where('c.type', 'customer')->when($manager !== null, fn ($q) => $q->where('c.manager_id', $manager));
            $overdue = $customers()->where('b.overdue_amount', '>', 0.005)->selectRaw('COUNT(*) AS n, SUM(b.overdue_amount) AS amount, MAX(b.overdue_days) AS days')->first();
            $add('overdue_debt', 'danger', __('Gecikmiş müştəri borcu'),
                __(':count müştəri · :amount · ən çox :days gün', ['count' => (int) $overdue->n, 'amount' => Fmt::money((float) $overdue->amount), 'days' => (int) $overdue->days]),
                (int) $overdue->n, route('debts.index', ['debts_sort' => 'overdue_amount', 'debts_dir' => 'desc']));
            $over = $customers()->whereNotNull('c.credit_limit')->whereColumn('b.balance', '>', 'c.credit_limit')
                ->selectRaw('COUNT(*) AS n, SUM(b.balance - c.credit_limit) AS amount')->first();
            $add('credit_limit', 'warn', __('Kredit limiti aşılıb'),
                __(':count müştəri · limitdən artıq :amount', ['count' => (int) $over->n, 'amount' => Fmt::money((float) $over->amount)]),
                (int) $over->n, route('debts.index', ['debts_sort' => 'balance', 'debts_dir' => 'desc']));
        }

        if ($can('suppliers.index')) {
            $late = collect(app(SupplierLedger::class)->balances())->filter(fn ($b) => $b['overdue'] > 0.005);
            $add('supplier_overdue', 'danger', __('Təchizatçıya gecikmiş ödəniş'),
                __(':count təchizatçının ödəniş tarixi keçib', ['count' => $late->count()]), $late->count(), route('suppliers.index'));
        }

        if ($can('deliveries.index')) {
            $open = DB::table('delivery_notes as d')->whereNull('d.cancelled_at')
                ->when($manager !== null, fn ($q) => $q->where('d.manager_id', $manager))
                ->whereNotExists(fn ($q) => $q->from('sales_invoices as i')->whereColumn('i.delivery_note_id', 'd.id')->whereNull('i.cancelled_at'))
                ->selectRaw('COUNT(*) AS n, SUM(d.total_amount) AS amount, MIN(d.date) AS oldest')->first();
            $add('uninvoiced', 'warn', __('Fakturalanmamış təhvil-təslim'),
                __(':count sənəd · :amount · ən köhnəsi :date', ['count' => (int) $open->n, 'amount' => Fmt::money((float) $open->amount), 'date' => Fmt::date($open->oldest)]),
                (int) $open->n, route('deliveries.index', ['preset' => 'all', 'only' => 'uninvoiced']));
        }

        if ($can('stock.index')) {
            $stock = app(StockService::class)->balances(new ReportFilters(now()->startOfYear(), now()->endOfDay()));
            $negative = $stock->filter(fn ($r) => $r->qty < -0.0001)->count();
            $add('negative_stock', 'danger', __('Mənfi qalıq'), __(':count malın qalığı mənfidir — sənəd çatışmır', ['count' => $negative]),
                $negative, route('stock.index', ['only' => 'negative']));
            $low = $stock->where('low_stock', true)->count();
            $add('low_stock', 'warn', __('Az qalan mallar'), __(':count mal minimum həddə çatıb', ['count' => $low]), $low, route('stock.index', ['only' => 'low']));
        }

        if ($can('batches.index')) {
            $batches = app(BatchService::class)->batches();
            $expired = $batches->where('state', 'expired');
            $add('expired', 'danger', __('Vaxtı bitmiş partiyalar'),
                __(':count partiya · dəyəri :amount — silinmə aktı lazımdır', ['count' => $expired->count(), 'amount' => Fmt::money($expired->sum('value'))]),
                $expired->count(), route('batches.index', ['tab' => 'expired']));
            $soon = $batches->where('state', 'soon');
            $add('expiring', 'warn', __('Son istifadə tarixi yaxınlaşır'),
                __(':count partiya · dəyəri :amount', ['count' => $soon->count(), 'amount' => Fmt::money($soon->sum('value'))]),
                $soon->count(), route('batches.index', ['tab' => 'soon']));
        }

        if ($can('backups.index')) {
            $last = app(BackupService::class)->list()[0]['at'] ?? null;
            if ($last === null || $last->lt(now()->subHours(48))) {
                $add('backup', 'warn', __('Ehtiyat nüsxəsi köhnədir'),
                    $last ? __('Son nüsxə: :date', ['date' => $last->format('d.m.Y H:i')]) : __('Hələ heç bir nüsxə yoxdur'), 1, route('backups.index'));
            }
        }

        usort($alerts, fn ($a, $b) => ($a['level'] === 'danger' ? 0 : 1) <=> ($b['level'] === 'danger' ? 0 : 1));

        return $alerts;
    }
}
