<?php

namespace App\Services;

use App\Exceptions\DocumentActionException;
use App\Support\AppSettings;
use App\Support\Fmt;
use Illuminate\Support\Carbon;

/**
 * Dövr bağlama: göstərilən tarix (daxil olmaqla) və ondan əvvəlki günlərə sənəd yazmaq, dəyişmək və ya ləğv etmək olmaz.
 * Hesabatı verilmiş ayın rəqəmləri sonradan səssizcə dəyişməsin deyə.
 */
class PeriodLock
{
    public const KEY = 'period_locked_until';

    public static function lockedUntil(): ?Carbon
    {
        $value = AppSettings::get(self::KEY);

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    public static function isLocked(Carbon|string|null $date): bool
    {
        $until = self::lockedUntil();

        return $until !== null && $date !== null && Carbon::parse(substr((string) $date, 0, 10))->startOfDay()->lte($until);
    }

    /**
     * @throws DocumentActionException
     */
    public static function assertOpen(Carbon|string|null $date): void
    {
        if (self::isLocked($date)) {
            throw new DocumentActionException(__('Dövr bağlanıb: :until daxil olmaqla əvvəlki tarixlərə sənəd yazılmır, dəyişdirilmir və ləğv edilmir (tarix: :date). Lazımdırsa admin «Dövr bağlama» səhifəsində dövrü açmalıdır.', [
                'until' => Fmt::date(self::lockedUntil()), 'date' => Fmt::date(substr((string) $date, 0, 10)),
            ]));
        }
    }

    public static function set(?string $date, ?int $userId): void
    {
        AppSettings::set(self::KEY, $date ? Carbon::parse($date)->toDateString() : null, $userId);
    }
}
