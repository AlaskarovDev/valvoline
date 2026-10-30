<?php

namespace App\Services;

use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sifariş təsdiqi: qayda aşılıbsa sifariş «təsdiq gözləyir» olur; rəhbər təsdiqləyir və ya rədd edir.
 * Sifariş dəyişdirilib yenidən göndəriləndə köhnə sorğu «superseded» olur.
 */
class ApprovalService
{
    /**
     * @param  list<string>  $reasons  boşdursa sifariş təsdiq tələb etmir
     */
    public function sync(SalesOrder $order, array $reasons, float $amount, int $userId): void
    {
        DB::table('approval_requests')->where('subject_type', 'sales_order')->where('subject_id', $order->id)->where('status', 'pending')
            ->update(['status' => 'superseded', 'updated_at' => now()]);
        if (! $reasons) {
            if ($order->approval_status !== 'approved') {
                $order->forceFill(['approval_status' => null])->save();
            }

            return;
        }
        DB::table('approval_requests')->insert(['subject_type' => 'sales_order', 'subject_id' => $order->id, 'reasons' => json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE),
            'amount' => round($amount, 2), 'status' => 'pending', 'requested_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $order->forceFill(['approval_status' => 'pending'])->save();
        AlertService::touch();
    }

    public function decide(int $requestId, bool $approve, ?string $note, int $userId): SalesOrder
    {
        $request = DB::table('approval_requests')->where('id', $requestId)->first();
        if (! $request || $request->status !== 'pending') {
            throw new RuntimeException(__('Bu sorğu artıq baxılıb.'));
        }
        $order = SalesOrder::query()->findOrFail($request->subject_id);
        DB::transaction(function () use ($request, $order, $approve, $note, $userId) {
            DB::table('approval_requests')->where('id', $request->id)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $userId,
                'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
            $order->forceFill(['approval_status' => $approve ? 'approved' : 'rejected'])->save();
        });

        return $order;
    }

    /**
     * Təhvil / faktura əvvəli yoxlama.
     */
    public static function assertCleared(SalesOrder $order): void
    {
        if ($order->approval_status === 'pending') {
            throw new RuntimeException(__('Sifariş rəhbərin təsdiqini gözləyir — təsdiqlənməmiş göndərilmir.'));
        }
        if ($order->approval_status === 'rejected') {
            throw new RuntimeException(__('Sifariş təsdiqlənməyib (rədd edilib) — dəyişib yenidən təsdiqə göndərin.'));
        }
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return ['pending' => __('Təsdiq gözləyir'), 'approved' => __('Təsdiqlənib'), 'rejected' => __('Rədd edilib'), 'superseded' => __('Yenisi ilə əvəzlənib')];
    }
}
