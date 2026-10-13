<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dil seçimi: sessiya → cookie → standart (az).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasSession() ? $request->session()->get('locale') : null;
        $locale ??= $request->cookie(Locale::COOKIE);
        if (! Locale::isSupported($locale)) {
            $locale = config('app.locale', Locale::DEFAULT);
        }
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
