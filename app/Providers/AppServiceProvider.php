<?php

namespace App\Providers;

use App\Exchanges\ExchangeDrivers;
use App\Models\Contracts\Owned;
use App\Models\User;
use App\Policies\OwnerPolicy;
use App\Support\Settings;
use App\Support\TrustedProxies;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        // One for each request or command, so the drivers it makes do not outlive it.
        $this->app->scoped(ExchangeDrivers::class);
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

        TrustedProxies::apply();

        // The API: so many requests a minute for each token.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('nugget.api.per_minute'))
            ->by((string) ($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())));
    }
}
