<?php

namespace App\Services;

use App\Mail\DailyDigest;
use App\Models\User;
use App\Support\Fmt;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Gündəlik e-mail xülasəsi: dünənki satış / ödəniş / sifarişlər, ay ərzində satış və bildirişlər.
 * Hər istifadəçi öz icazəsi daxilində görür (menecer — öz müştəriləri). Səhər 08:00-dan (Bakı) sonra gündə bir dəfə göndərilir.
 */
class DigestService
{
    public const SEND_AFTER_HOUR = 8;

    public const TIMEZONE = 'Asia/Baku';

    /**
     * @return array{date: string, user: string, kpis: list<array{label: string, value: string}>, alerts: list<array<string, mixed>>, url: string}
     */
    public function build(User $user): array
    {
        $yesterday = now(self::TIMEZONE)->subDay()->toDateString();
        $monthStart = now(self::TIMEZONE)->subDay()->startOfMonth()->toDateString();
        $manager = $user->restrictedManagerId();
        $kpis = [];
        if (Permissions::canRoute($user, 'sales.index')) {
            $sales = fn (string $from) => (float) DB::table('sales_lines')->where('op_type', 'sale')->whereDate('date', '>=', $from)->whereDate('date', '<=', $yesterday)
                ->when($manager !== null, fn ($q) => $q->where('manager_id', $manager))->sum('net_amount');
            $kpis[] = ['label' => __('Dünənki satış'), 'value' => Fmt::money($sales($yesterday))];
            $kpis[] = ['label' => __('Ay ərzində satış'), 'value' => Fmt::money($sales($monthStart))];
            $orders = DB::table('sales_orders')->whereDate('date', $yesterday)->when($manager !== null, fn ($q) => $q->where('manager_id', $manager))->count();
            $kpis[] = ['label' => __('Dünənki sifarişlər'), 'value' => Fmt::number($orders)];
        }
        if (Permissions::canRoute($user, 'payments.index')) {
            $paid = (float) DB::table('payments')->whereDate('date', $yesterday)->where('total', '>', 0)
                ->when($manager !== null, fn ($q) => $q->where('manager_id', $manager))->sum('total');
            $kpis[] = ['label' => __('Dünən daxil olan ödəniş'), 'value' => Fmt::money($paid)];
        }

        return ['date' => Fmt::date($yesterday), 'user' => $user->name, 'kpis' => $kpis,
            'alerts' => app(AlertService::class)->forUser($user, fresh: true), 'url' => route('dashboard')];
    }

    /**
     * Vaxtı çatıbsa, abunəçilərə bu günün xülasəsini göndərir.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendDue(bool $force = false): array
    {
        $now = now(self::TIMEZONE);
        if (! $force && $now->hour < self::SEND_AFTER_HOUR) {
            return ['sent' => 0, 'failed' => 0];
        }
        $today = $now->toDateString();
        $sent = 0;
        $failed = 0;
        $users = User::query()->where('is_active', true)->where('digest_enabled', true)
            ->where(fn ($q) => $q->whereNull('digest_sent_on')->orWhereDate('digest_sent_on', '<', $today))->get();
        // xülasə sistemin əsas dilində yazılır (göndərişi kimin sorğusunun işə saldığından asılı olmasın)
        $locale = app()->getLocale();
        app()->setLocale(config('app.locale'));
        try {
            foreach ($users as $user) {
                try {
                    Mail::to($user)->send(new DailyDigest($this->build($user)));
                    $user->forceFill(['digest_sent_on' => $today])->save();
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        } finally {
            app()->setLocale($locale);
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
