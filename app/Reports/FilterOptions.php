<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Filtr panelindəki seçim siyahıları (sorğu ərzində bir dəfə oxunur).
 */
class FilterOptions
{
    /** @var array<string, array<int|string, string>> */
    private static array $cache = [];

    /**
     * @return array<int|string, string>
     */
    public static function get(string $type, ?int $managerId = null): array
    {
        $key = $type.':'.$managerId;

        return self::$cache[$key] ??= match ($type) {
            'manager' => DB::table('managers')->orderBy('name')->pluck('name', 'id')->all(),
            'customer' => DB::table('customers')->when($managerId, fn ($q) => $q->where('manager_id', $managerId))
                ->orderBy('name')->get(['id', 'name', 'code'])->mapWithKeys(fn ($c) => [$c->id => $c->name.' · '.$c->code])->all(),
            'product' => DB::table('products')->orderBy('name')->get(['id', 'name', 'code'])->mapWithKeys(fn ($p) => [$p->id => $p->code.' — '.$p->name])->all(),
            'group' => DB::table('product_groups')->orderBy('name')->pluck('name', 'id')->all(),
            'category' => DB::table('categories')->orderBy('name')->pluck('name', 'id')->all(),
            'series' => DB::table('products')->whereNotNull('series')->distinct()->orderBy('series')->pluck('series', 'series')->all(),
            'direction' => DB::table('cash_transactions')->whereNotNull('direction')->distinct()->orderBy('direction')->pluck('direction', 'direction')->all(),
            default => [],
        };
    }
}
