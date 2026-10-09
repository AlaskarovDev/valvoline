<?php

namespace App\Support;

/**
 * Müştəri adlarının müqayisəsi üçün normallaşdırma və oxşarlıq.
 * Azərbaycan hərfləri latın ekvivalentinə çevrilir: ə→e, ı→i, ö→o, ü→u, ş→s, ç→c, ğ→g.
 */
class NameNormalizer
{
    private const MAP = [
        'Ə' => 'e', 'ə' => 'e', 'I' => 'i', 'ı' => 'i', 'İ' => 'i', 'i̇' => 'i',
        'Ö' => 'o', 'ö' => 'o', 'Ü' => 'u', 'ü' => 'u', 'Ş' => 's', 'ş' => 's',
        'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g',
    ];

    public static function normalize(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $value = strtr(trim($name), self::MAP);
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, self::MAP);
        $value = preg_replace('/[«»"“”„\'’`.,;:!?()\[\]{}\/\\\\_\-–—]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * 0..1 oxşarlıq balı: similar_text, Levenshtein və söz üst-üstə düşməsinin ən yaxşısı.
     */
    public static function similarity(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);
        $scores = [$percent / 100];

        $maxLength = max(strlen($a), strlen($b));
        if ($maxLength <= 255) {
            $scores[] = 1 - levenshtein($a, $b) / $maxLength;
        }

        $tokensA = array_unique(explode(' ', $a));
        $tokensB = array_unique(explode(' ', $b));
        $common = count(array_intersect($tokensA, $tokensB));
        $scores[] = $common / max(count($tokensA), count($tokensB));

        if (str_contains($a, $b) || str_contains($b, $a)) {
            $scores[] = 0.85;
        }

        return max($scores);
    }
}
