<?php

namespace App\Models;

use App\Models\Concerns\Cancellable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWriteOff extends Model
{
    use Cancellable;

    protected $table = 'stock_write_offs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
