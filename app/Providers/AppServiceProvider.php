<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->callAfterResolving(RateLimiter::class, function (RateLimiter $limiter): void {
            $limiter->for('api', fn (Request $request) => Limit::perMinute(config('api.rate_limit_per_minute'))
                ->by((string) $request->attributes->get('api_key_id'))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many requests.', 'code' => 'too_many_requests',
                ], 429, $headers)));
        });
    }
}
