<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    protected $table = 'import_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sheets' => 'array', 'errors' => 'array', 'imported_at' => 'datetime', 'rolled_back_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
