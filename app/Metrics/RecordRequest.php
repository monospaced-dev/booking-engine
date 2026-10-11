<?php

namespace App\Metrics;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Log;
use Prometheus\CollectorRegistry;
use Throwable;

class RecordRequest
{
    public function handle(RequestHandled $event): void
    {
        try {
            $this->record($event);
        } catch (Throwable $exception) {
            try {
                Log::warning('Failed to record request metrics.', ['exception' => $exception]);
            } catch (Throwable) {
                // A failed logging backend must not turn instrumentation into an HTTP failure.
            }
        }
    }

    private function record(RequestHandled $event): void
    {
        $request = $event->request;
        if ($request->is('metrics')) {
            return;
        }

        $start = $request->attributes->get('metrics_started_at');
        if ($start === null) {
            return;
        }

        $registry = app(CollectorRegistry::class);
        $labels = [$request->method(), $request->route() ? '/'.$request->route()->uri() : 'unmatched', (string) $event->response->getStatusCode()];
        $labelNames = ['method', 'route', 'status'];
        $registry->getOrRegisterCounter('booking_engine', 'http_requests_total', 'HTTP requests handled.', $labelNames)->inc($labels);
        $registry->getOrRegisterHistogram('booking_engine', 'http_request_duration_seconds', 'HTTP request duration in seconds.', $labelNames)
            ->observe((hrtime(true) - $start) / 1e9, $labels);
    }
}
