<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $table = 'warehouses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return ['main' => __('Əsas anbar'), 'van' => __('Səyyar maşın'), 'regional' => __('Regional anbar'), 'transit' => __('Tranzit (yolda)'), 'quarantine' => __('Karantin')];
    }

    /**
     * Satış və transfer seçimlərində görünən anbarlar (tranzit və karantin xaric).
     *
     * @param  Builder<self>  $query
     */
    public function scopeSellable($query): void
    {
        $query->where('is_active', true)->whereIn('type', ['main', 'van', 'regional']);
    }
}
