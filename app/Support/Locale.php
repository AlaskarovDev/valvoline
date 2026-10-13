<?php

namespace App\Support;

/**
 * İnterfeys dilləri. Azərbaycanca mətnlər həm də tərcümə açarıdır (lang/ru.json, lang/en.json).
 */
class Locale
{
    /** @var array<string, string> kod => öz dilində ad */
    public const SUPPORTED = ['az' => 'Azərbaycanca', 'ru' => 'Русский', 'en' => 'English'];

    public const DEFAULT = 'az';

    public const COOKIE = 'locale';

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::SUPPORTED);
    }

    /**
     * JS tərəfində (ApexCharts, popup) lazım olan qısa mətnlər.
     *
     * @return array<string, string>
     */
    public static function jsStrings(): array
    {
        return [
            'total' => __('Cəmi'),
            'thousand' => __('min'),
            'million' => __('mln'),
            'margin' => __('marja'),
            'loading' => __('Yüklənir…'),
            'loadError' => __('Məlumat yüklənmədi. «Ayrıca səhifədə aç» ilə yoxlayın.'),
        ];
    }
}
