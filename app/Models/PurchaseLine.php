<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseLine extends Model
{
    protected $table = 'purchase_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_estimated' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
