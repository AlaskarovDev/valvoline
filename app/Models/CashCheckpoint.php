<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashCheckpoint extends Model
{
    protected $table = 'cash_checkpoints';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
