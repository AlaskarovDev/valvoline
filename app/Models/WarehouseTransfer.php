<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTransfer extends Model
{
    protected $table = 'warehouse_transfers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'sent_date' => 'date', 'received_date' => 'date', 'sent_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return ['draft' => __('Qaralama'), 'sent' => __('Yolda'), 'partial' => __('Qismən qəbul'), 'received' => __('Qəbul edilib'), 'cancelled' => __('Ləğv edilib')];
    }

    /**
     * @return array<string, string>
     */
    public static function reasonLabels(): array
    {
        return ['replenish' => __('Doldurma'), 'van_load' => __('Maşına yükləmə'), 'van_return' => __('Maşından qaytarma'), 'rebalance' => __('Anbarlar arası balans'), 'quarantine' => __('Karantinə'), 'other' => __('Digər')];
    }
}
