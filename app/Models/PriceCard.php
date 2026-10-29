<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceCard extends Model
{
    protected $table = 'price_cards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'float', 'min_qty' => 'float', 'vat_included' => 'boolean', 'is_active' => 'boolean',
            'valid_from' => 'date', 'valid_to' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}
