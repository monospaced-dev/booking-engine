<?php

namespace App\Metrics;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Prometheus\CollectorRegistry;

class RecordRequest
{
    public function __construct(private CollectorRegistry $registry) {}

    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        if ($request->is('metrics')) {
            return;
        }

        $start = $request->attributes->get('metrics_started_at');
        if ($start === null) {
            return;
        }

        $labels = [$request->method(), $request->route() ? '/'.$request->route()->uri() : 'unmatched', (string) $event->response->getStatusCode()];
        $labelNames = ['method', 'route', 'status'];
        $this->registry->getOrRegisterCounter('booking_engine', 'http_requests_total', 'HTTP requests handled.', $labelNames)->inc($labels);
        $this->registry->getOrRegisterHistogram('booking_engine', 'http_request_duration_seconds', 'HTTP request duration in seconds.', $labelNames)
            ->observe((hrtime(true) - $start) / 1e9, $labels);
    }
}
