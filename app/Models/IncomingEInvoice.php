<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daxil olan (alış) e-qaimə — e-taxes Excel çıxışından (EQF) yüklənir.
 */
class IncomingEInvoice extends Model
{
    protected $table = 'incoming_einvoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'items' => 'array'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseDocument(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class);
    }

    /** Ləğv edilmiş / silinmiş qaimədən mədaxil yaradılmır. */
    public function isVoid(): bool
    {
        return (bool) preg_match('/ləğv|silin|rədd/iu', (string) $this->status);
    }
}
