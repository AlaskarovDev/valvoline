<?php

namespace App\Models;

use App\Models\Concerns\Cancellable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Təchizatçıya alış sifarişi. Vəziyyət sənədə yazılmır — bağlı mədaxillərdən hesablanır (PurchaseOrderService::progress).
 */
class PurchaseOrder extends Model
{
    use Cancellable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'expected_date' => 'date', 'closed_at' => 'datetime'];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return ['open' => __('Gözlənilir'), 'partial' => __('Qismən qəbul edilib'), 'received' => __('Tam qəbul edilib'), 'closed' => __('Bağlanıb'), 'cancelled' => __('Ləğv edilib')];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
