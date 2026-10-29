<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    protected $table = 'price_lists';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['vat_included' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
