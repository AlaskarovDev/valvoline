<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $table = 'warehouses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean'];
    }
}
