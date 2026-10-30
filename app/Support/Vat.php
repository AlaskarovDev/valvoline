<?php

namespace App\Support;

/**
 * ƏDV hesablaması. Qiymətlər sistemdə ƏDV daxil saxlanılır: ƏDV = məbləğ × dərəcə / (100 + dərəcə).
 * Rejimlər (e-qaimə): standard — dərəcə ilə, zero — 0 %, exempt — azad, not_subject — cəlb olunmayan.
 */
class Vat
{
    /**
     * @return array<string, string>
     */
    public static function modeLabels(): array
    {
        return ['standard' => __('ƏDV-yə cəlb olunan'), 'zero' => __('0 % dərəcə'), 'exempt' => __('ƏDV-dən azad'), 'not_subject' => __('ƏDV-yə cəlb olunmayan')];
    }

    public static function rate(?string $mode, float|string|null $rate): float
    {
        return ($mode ?? 'standard') === 'standard' ? (float) ($rate ?? 18) : 0.0;
    }

    /**
     * ƏDV daxil məbləği bölür.
     *
     * @return array{rate: float, vat: float, net: float}
     */
    public static function split(float $gross, ?string $mode, float|string|null $rate): array
    {
        $r = self::rate($mode, $rate);
        $vat = $r > 0 ? round($gross * $r / (100 + $r), 4) : 0.0;

        return ['rate' => $r, 'vat' => $vat, 'net' => round($gross - $vat, 4)];
    }

    /**
     * Sətirlər üzrə rejim / dərəcə qrupları (çap və e-qaimə üçün): «18 %», «ƏDV-dən azad» …
     *
     * @param  iterable<object>  $lines  vat_mode, vat_rate, net_amount, vat_amount sahələri ilə
     * @return list<array{label: string, base: float, vat: float, gross: float}>
     */
    public static function summary(iterable $lines): array
    {
        $groups = [];
        foreach ($lines as $l) {
            if (($l->op_type ?? 'sale') !== 'sale') {
                continue;
            }
            $mode = $l->vat_mode ?? 'standard';
            $key = $mode === 'standard' ? 'r'.(float) $l->vat_rate : $mode;
            $label = $mode === 'standard' ? __('ƏDV :rate %', ['rate' => rtrim(rtrim(number_format((float) $l->vat_rate, 2, ',', ''), '0'), ',')]) : (self::modeLabels()[$mode] ?? $mode);
            $groups[$key] ??= ['label' => $label, 'base' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
            $groups[$key]['vat'] += (float) $l->vat_amount;
            $groups[$key]['gross'] += (float) $l->net_amount;
            $groups[$key]['base'] += (float) $l->net_amount - (float) $l->vat_amount;
        }

        return array_values($groups);
    }
}
