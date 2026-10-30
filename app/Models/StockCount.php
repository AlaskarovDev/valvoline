<?php

namespace App\Models;

use App\Models\Concerns\Cancellable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anbar sayımı (SY-): qaralama → tamamlanıb (artıq / əskik sənədləri yaradılır).
 */
class StockCount extends Model
{
    use Cancellable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'completed_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function surplus(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'surplus_document_id');
    }

    public function shortage(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'shortage_document_id');
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null && $this->cancelled_at === null;
    }
}
