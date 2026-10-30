<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        // 2FA kodu: gözləyən istifadəçi + IP üzrə dəqiqədə 5 cəhd (parol cəhdlərinin sayğacından ayrı)
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by('2fa|'.($request->session()->get('login.2fa')['id'] ?? '').'|'.$request->ip()));
        // bölmə icazələri: @can('stock.manage'), $user->can('finance') …
        foreach (array_keys(Permissions::areas()) as $area) {
            Gate::define($area, fn (User $user) => $user->hasPermission($area));
        }
        Paginator::defaultView('components.pagination');
    }
}
