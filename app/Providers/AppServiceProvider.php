<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('employee-search', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('employee-search:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('report-search', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('report-search:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('imports', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('imports:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('exports', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('exports:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Livewire::addPersistentMiddleware([ThrottleRequests::class]);
    }
}
