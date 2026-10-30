<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rolu üçün 2FA məcburi olan istifadəçi onu qoşmayana qədər yalnız «Hesabım» səhifəsinə girə bilər.
 */
class EnsureTwoFactor
{
    private const ALLOWED = ['account.*', 'logout', 'locale'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $request->routeIs(...self::ALLOWED)) {
            $twoFactor = app(TwoFactorService::class);
            if ($twoFactor->isRequired($user) && ! $twoFactor->enabled($user)) {
                return $request->expectsJson()
                    ? response()->json(['message' => __('İki mərhələli girişi qoşun.')], 403)
                    : redirect()->route('account.index')->with('error', __('Sizin rolunuz üçün iki mərhələli giriş məcburidir — davam etmək üçün onu qoşun.'));
            }
        }

        return $next($request);
    }
}
