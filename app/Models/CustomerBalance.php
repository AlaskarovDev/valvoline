<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBalance extends Model
{
    protected $table = 'customer_balances';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['oldest_unpaid_date' => 'date', 'first_sale_date' => 'date', 'last_sale_date' => 'date', 'last_payment_date' => 'date', 'as_of' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
