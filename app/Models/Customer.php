<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $table = 'customers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_sale' => 'date', 'last_sale' => 'date'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CustomerAlias::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(CustomerBalance::class);
    }
}
