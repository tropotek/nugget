<?php

namespace App\Providers;

use App\Models\Contracts\Owned;
use App\Models\User;
use App\Policies\OwnerPolicy;
use App\Support\Settings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());

        // Every Owned model shares OwnerPolicy. Anything else has its own App\Policies\{Model}Policy.
        Gate::guessPolicyNamesUsing(fn (string $model) => is_subclass_of($model, Owned::class)
            ? OwnerPolicy::class
            : 'App\\Policies\\'.class_basename($model).'Policy');

        Paginator::useBootstrapFive();
    }
}
