<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Göstərmə formatları: 1 234,56 ₼ · dd.mm.yyyy · 35,5 %.
 * Hesablamalar tam dəqiqliklə aparılır, yuvarlaqlaşdırma yalnız burada olur.
 */
class Fmt
{
    /** Nazik bölünməz boşluq — minliklər üçün. */
    public const THOUSANDS = "\u{202F}";

    public static function money(float|int|string|null $value, int $decimals = 2, bool $symbol = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return self::number((float) $value, $decimals).($symbol ? "\u{00A0}₼" : '');
    }

    public static function number(float|int|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $value = (float) $value;
        if (abs($value) < 0.5 * 10 ** (-$decimals)) {
            $value = 0.0;
        }
        $formatted = number_format(abs($value), $decimals, ',', self::THOUSANDS);

        return ($value < 0 ? '−' : '').$formatted;
    }

    public static function qty(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $value = (float) $value;

        return self::number($value, fmod(abs($value), 1.0) > 0.00001 ? 2 : 0);
    }

    public static function pct(float|int|string|null $ratio, int $decimals = 1): string
    {
        if ($ratio === null || $ratio === '') {
            return '—';
        }

        return self::number((float) $ratio * 100, $decimals)."\u{00A0}%";
    }

    public static function date(DateTimeInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        return $date->format('d.m.Y');
    }

    public static function period(string $period): string
    {
        [$year, $month] = explode('-', $period) + [1 => '01'];

        return self::monthName((int) $month, true).' '.$year;
    }

    public static function monthName(int $month, bool $short = false): string
    {
        $full = [1 => 'Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'İyun', 'İyul', 'Avqust', 'Sentyabr', 'Oktyabr', 'Noyabr', 'Dekabr'];
        $abbr = [1 => 'Yan', 'Fev', 'Mar', 'Apr', 'May', 'İyn', 'İyl', 'Avq', 'Sen', 'Okt', 'Noy', 'Dek'];

        return ($short ? $abbr : $full)[$month] ?? (string) $month;
    }

    /**
     * Əvvəlki dövrlə müqayisə: ▲ 12,3 % / ▼ 4,0 %. Baza sıfırdırsa null.
     */
    public static function delta(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.0001) {
            return null;
        }

        return ($current - $previous) / abs($previous);
    }

    public static function carbonOrNull(?string $value): ?CarbonInterface
    {
        return $value ? Carbon::parse($value) : null;
    }
}
