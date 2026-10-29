<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'products';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cost_estimated' => 'boolean', 'is_active' => 'boolean', 'vat_rate' => 'float'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function productClass(): BelongsTo
    {
        return $this->belongsTo(ProductClass::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function priceCards(): HasMany
    {
        return $this->hasMany(PriceCard::class);
    }

    /**
     * ƏDV əmsalı: standart dərəcədə 1 + dərəcə/100, sıfır dərəcəli və azad mallar üçün 1.
     */
    public function vatFactor(): float
    {
        return $this->vat_mode === 'standard' ? 1 + (float) $this->vat_rate / 100 : 1.0;
    }
}
