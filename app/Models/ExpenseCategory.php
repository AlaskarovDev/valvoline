<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $table = 'expense_categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['affects_profit' => 'boolean', 'user_edited' => 'boolean'];
    }
}
