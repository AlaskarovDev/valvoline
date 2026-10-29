<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workplace extends Model
{
    protected $table = 'workplaces';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * İstifadəçinin iş yeri, yoxdursa əsas iş yeri.
     */
    public static function defaultIdFor(?User $user): ?int
    {
        return $user?->workplace_id ?? self::query()->where('is_default', true)->value('id') ?? self::query()->value('id');
    }
}
