<?php

namespace App\Models;

use App\Support\AuditLabels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return AuditLabels::label($this->action);
    }
}
