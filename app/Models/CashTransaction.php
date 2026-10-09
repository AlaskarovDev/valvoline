<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransaction extends Model
{
    protected $table = 'cash_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'affects_profit_raw' => 'boolean', 'affects_profit' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
