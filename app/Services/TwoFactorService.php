<?php

namespace App\Services;

use App\Models\User;
use App\Support\AppSettings;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

/**
 * İki mərhələli giriş: qoşma (açar → QR → kodla təsdiq), girişdə kod və ya birdəfəlik ehtiyat kod, admin tərəfindən sıfırlama.
 * Admin seçdiyi rollar üçün 2FA məcburi edilə bilər (app_settings.two_factor_roles).
 */
class TwoFactorService
{
    public const SETTING = 'two_factor_roles';

    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    /**
     * @return list<string>
     */
    public static function requiredRoles(): array
    {
        $roles = json_decode((string) AppSettings::get(self::SETTING), true);

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    public function isRequired(User $user): bool
    {
        return in_array($user->role, self::requiredRoles(), true);
    }

    public function qrSvg(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

        return preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    /**
     * Açar təsdiq olunana qədər yalnız sessiyada saxlanılır — yarımçıq qoşma hesabı bağlamır.
     *
     * @return list<string> ehtiyat kodlar (bir dəfə göstərilir)
     */
    public function confirm(User $user, string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $codes, 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();

        return $codes;
    }

    /**
     * Girişdə kod: authenticator kodu və ya ehtiyat kod (işlənən ehtiyat kod silinir).
     */
    public function verify(User $user, ?string $code, ?string $recovery): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }
        if ($code !== null && $code !== '') {
            $step = Totp::verify((string) $user->two_factor_secret, $code, $user->two_factor_last_step);
            if ($step !== null) {
                $user->forceFill(['two_factor_last_step' => $step])->save();

                return true;
            }

            return false;
        }
        $recovery = strtolower(trim((string) $recovery));
        $codes = (array) $user->two_factor_recovery_codes;
        foreach ($codes as $i => $stored) {
            if ($recovery !== '' && hash_equals($stored, $recovery)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        return array_map(fn () => strtolower(Str::random(5).'-'.Str::random(5)), range(1, 8));
    }
}
