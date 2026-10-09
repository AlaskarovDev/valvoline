<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseBatch extends Model
{
    protected $table = 'purchase_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
