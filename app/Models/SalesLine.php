<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLine extends Model
{
    protected $table = 'sales_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'cost_fallback' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
