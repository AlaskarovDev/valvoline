<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Ümumi tənzimləmələr (app_settings cədvəli): açar → mətn dəyər. Sorğu ərzində keşlənir.
 */
class AppSettings
{
    /** @var array<string, string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::$cache ??= DB::table('app_settings')->pluck('value', 'key')->all();

        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, ?string $value, ?int $userId = null): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
