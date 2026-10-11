<?php

namespace App\Providers;

use App\Metrics\RecordRequest;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Adapter::class, fn () => match (config('metrics.storage')) {
            'memory' => new InMemory,
            'apcu' => extension_loaded('apcu') && apcu_enabled() ? new APC : new InMemory,
            default => throw new \InvalidArgumentException('METRICS_STORAGE must be apcu or memory.'),
        });
        $this->app->singleton(CollectorRegistry::class, fn ($app) => new CollectorRegistry($app->make(Adapter::class), false));
        if ($this->app->environment('local')) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(RequestHandled::class, RecordRequest::class);
        $this->callAfterResolving(RateLimiter::class, function (RateLimiter $limiter): void {
            $limiter->for('api', fn (Request $request) => Limit::perMinute(config('api.rate_limit_per_minute'))
                ->by((string) $request->attributes->get('api_key_id'))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many requests.', 'code' => 'too_many_requests',
                ], 429, $headers)));
        });
    }
}
