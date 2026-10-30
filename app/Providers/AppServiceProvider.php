<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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
        // bölmə icazələri: @can('stock.manage'), $user->can('finance') …
        foreach (array_keys(Permissions::areas()) as $area) {
            Gate::define($area, fn (User $user) => $user->hasPermission($area));
        }
        Paginator::defaultView('components.pagination');
    }
}
