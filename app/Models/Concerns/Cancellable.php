<?php

namespace App\Models\Concerns;

use App\Exceptions\DocumentActionException;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Silinmək əvəzinə ləğv edilən sənəd: cancelled_at / cancelled_by / cancel_reason.
 */
trait Cancellable
{
    public function initializeCancellable(): void
    {
        $this->mergeCasts(['cancelled_at' => 'datetime']);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('cancelled_at'));
    }

    /**
     * @throws DocumentActionException
     */
    public function assertNotCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new DocumentActionException(__('Bu sənəd artıq ləğv edilib.'));
        }
    }

    /**
     * Başlığı «ləğv edilib» kimi işarələyir (təsirini çağıran servis götürür).
     *
     * @param  array<string, mixed>  $extra
     */
    public function markCancelled(?int $userId, ?string $reason, array $extra = []): void
    {
        $this->forceFill(array_merge(['cancelled_at' => now(), 'cancelled_by' => $userId, 'cancel_reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null], $extra))->save();
    }
}
