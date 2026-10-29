<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    protected $table = 'product_units';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['factor' => 'float', 'is_sale_default' => 'boolean', 'is_purchase_default' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
