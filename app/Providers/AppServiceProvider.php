<?php

namespace App\Providers;

use App\Models\User;
use App\Services\OfficialRevenueService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // rəsmi gəlir qərarları sorğu ərzində bir dəfə hesablanır (P&L, idarə paneli, pul axını eyni nəticəni istifadə edir)
        $this->app->scoped(OfficialRevenueService::class);
    }

    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Paginator::defaultView('components.pagination');
    }
}
