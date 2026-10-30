<?php

namespace App\Models;

use App\Models\Concerns\Cancellable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseDocument extends Model
{
    use Cancellable;

    protected $table = 'purchase_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'supplier_invoice_date' => 'date', 'due_date' => 'date', 'vat_included' => 'boolean', 'rate' => 'float'];
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return ['receipt' => __('Mədaxil qaiməsi'), 'return' => __('Təchizatçıya qaytarma')];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
