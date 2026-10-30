<?php

namespace App\Support;

/**
 * Rollar və icazələr: hər rolun hansı bölmələrə girişi var. Admin həmişə hər şeyə icazəlidir.
 * Standart cədvəl aşağıdadır; admin «Rollar və icazələr» səhifəsində dəyişə bilər (app_settings.role_permissions).
 * Satış nümayəndəsi (menecer) əlavə olaraq yalnız öz müştərilərinin məlumatını görür.
 */
class Permissions
{
    public const SETTING = 'role_permissions';

    /**
     * @return array<string, array{0: string, 1: string}> açar => [ad, izah]
     */
    public static function areas(): array
    {
        return [
            'reports' => [__('Hesabatlar'), __('Satış, marja, qaytarma, borclar, ödənişlər, sifarişlər və qazanc')],
            'sales' => [__('Satış sənədləri'), __('Sifariş, təhvil-təslim, faktura, kampaniyalar')],
            'sales.manage' => [__('Satış: təsdiq'), __('Kampaniyaları təsdiqləmək, kredit limitini aşmağa icazə vermək')],
            'customers' => [__('Müştərilər'), __('Müştəri bazası, kart yaratmaq və düzəliş')],
            'products' => [__('Məhsullar (baxış)'), __('Məhsul kartları, qiymət siyahısı')],
            'products.manage' => [__('Məhsul idarəetməsi'), __('Məhsul kartı, qiymət kartları, parametrlər')],
            'purchases' => [__('Alış'), __('Təchizatçılar, mədaxil qaimələri, qaytarma, təchizatçı borcu və ödənişi')],
            'stock' => [__('Anbar'), __('Qalıq, hərəkətlər, transferlər, stok sənədləri')],
            'stock.manage' => [__('Anbar: idarəetmə'), __('Silinmə aktı, partiyalar, anbarlar, stok sənədini ləğv')],
            'finance' => [__('Kassa'), __('Kassalar, müştəri ödənişi, kassa transferi')],
            'finance.manage' => [__('Maliyyə: idarəetmə'), __('Açılış sənədləri, banklar, bank hesabları, sənədi ləğv')],
            'accounting' => [__('Mühasibat'), __('Balans, MZ, cash flow, xərclər, idxal, dövr bağlama')],
            'data' => [__('Məlumat'), __('Import, ad uyğunlaşdırma, məlumat keyfiyyəti')],
            'settings' => [__('Tənzimləmələr'), __('İstifadəçilər, rollar, iş yerləri, nömrələmə, nümayəndələr, audit')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roles(): array
    {
        return [
            'admin' => __('Admin'), 'rehber' => __('Rəhbər'), 'muhasib' => __('Mühasib'),
            'anbardar' => __('Anbardar'), 'kassir' => __('Kassir'), 'menecer' => __('Satış nümayəndəsi (menecer)'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        return [
            'rehber' => ['reports', 'sales', 'sales.manage', 'customers', 'products', 'purchases', 'stock', 'finance', 'accounting'],
            'muhasib' => ['reports', 'customers', 'products', 'purchases', 'stock', 'finance', 'finance.manage', 'accounting', 'data'],
            'anbardar' => ['products', 'purchases', 'stock', 'stock.manage'],
            'kassir' => ['customers', 'finance'],
            // əvvəlki davranış: menecer admin bölmələrindən başqa hər yerə girir (öz məlumatı ilə)
            'menecer' => ['reports', 'sales', 'customers', 'products', 'stock', 'finance'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $saved = json_decode((string) AppSettings::get(self::SETTING), true);
        $matrix = self::defaults();
        foreach (is_array($saved) ? $saved : [] as $role => $areas) {
            if (isset($matrix[$role]) && is_array($areas)) {
                $matrix[$role] = array_values(array_intersect(array_keys(self::areas()), $areas));
            }
        }

        return $matrix;
    }

    public static function allows(?string $role, string $area): bool
    {
        return $role === 'admin' || in_array($area, self::matrix()[$role] ?? [], true);
    }
}
