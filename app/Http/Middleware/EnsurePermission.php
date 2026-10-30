<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * perm:sales,products — istifadəçinin rolunda sadalanan bölmələrdən ən azı biri olmalıdır.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$areas): Response
    {
        $user = $request->user();
        abort_unless($user !== null && collect($areas)->contains(fn ($area) => $user->hasPermission($area)), 403, __('Bu bölməyə icazəniz yoxdur.'));

        return $next($request);
    }
}
