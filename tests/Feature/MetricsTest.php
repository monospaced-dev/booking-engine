<?php

use App\Actions\CreateBooking;
use Illuminate\Support\Facades\Log;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;

it('hides metrics without a configured token', function (?string $token) {
    config(['metrics.token' => $token]);
    $this->get('/metrics', ['Authorization' => 'Bearer anything'])->assertNotFound();
})->with([null, '']);

it('requires its own bearer token independently of API keys', function (?string $authorization) {
    config(['metrics.token' => 'scrape-secret']);
    $headers = apiKeyHeader();
    if ($authorization !== null) {
        $headers['Authorization'] = $authorization;
    }
    $this->get('/metrics', $headers)->assertUnauthorized();
})->with([null, 'Bearer wrong', 'Basic scrape-secret', 'Bearer scrape-secret-extra', 'Basic Bearer scrape-secret']);

it('exports counters and latency with bounded route labels and final statuses', function () {
    config(['metrics.token' => 'scrape-secret', 'api.rate_limit_per_minute' => 1]);
    expect(app(Adapter::class))->toBeInstanceOf(InMemory::class);
    $this->getJson('/api/ping')->assertNoContent();
    $this->getJson('/api/resources/123/bookings')->assertUnauthorized();
    $this->getJson('/api/resources/456/bookings')->assertUnauthorized();
    $this->getJson('/api/not-a-route-123')->assertNotFound();
    $this->withHeaders(apiKeyHeader())->postJson('/api/resources', [])->assertUnprocessable();
    $this->postJson('/api/resources', [])->assertStatus(429);
    $headers = ['Authorization' => 'Bearer scrape-secret'];
    $response = $this->get('/metrics', $headers)->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain; version=0.0.4');
    $body = $response->getContent();
    expect($body)->toContain('# TYPE booking_engine_http_requests_total counter', '# TYPE booking_engine_http_request_duration_seconds histogram');
    foreach ([['GET', '/api/ping', '204', 1], ['GET', '/api/resources/{resource}/bookings', '401', 2], ['GET', 'unmatched', '404', 1], ['POST', '/api/resources', '422', 1], ['POST', '/api/resources', '429', 1]] as [$method, $route, $status, $count]) {
        $labels = 'method="'.$method.'",route="'.$route.'",status="'.$status.'"';
        expect($body)->toContain('booking_engine_http_requests_total{'.$labels.'} '.$count, 'booking_engine_http_request_duration_seconds_count{'.$labels.'} '.$count);
    }
    expect($body)->not->toContain('/api/resources/123', '/api/resources/456', '/api/not-a-route-123', '/metrics');
    expect($this->get('/metrics', $headers)->getContent())->toBe($body);
});

it('counts rendered internal errors with status 500', function () {
    config(['metrics.token' => 'scrape-secret']);
    $this->mock(CreateBooking::class)->shouldReceive('handle')->andThrow(new RuntimeException('failure'));
    $resource = App\Models\Resource::factory()->create();
    $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T10:00:00Z',
    ])->assertStatus(500);
    expect($this->get('/metrics', ['Authorization' => 'Bearer scrape-secret'])->getContent())
        ->toContain('route="/api/resources/{resource}/bookings",status="500"');
});

it('preserves successful API responses and warns when recording metrics throws', function (string $method, Throwable $failure) {
    $resource = App\Models\Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    Log::spy();
    $registry = $this->mock(CollectorRegistry::class);
    if ($method === 'getOrRegisterHistogram') {
        $registry->shouldReceive('getOrRegisterCounter')->once()->andReturn(new Counter(
            new InMemory, 'test', 'requests', 'Test counter.', ['method', 'route', 'status']
        ));
    }
    $registry->shouldReceive($method)->once()->andThrow($failure);

    $this->getJson("/api/resources/{$resource->id}/bookings")->assertOk()->assertExactJson([]);
    Log::shouldHaveReceived('warning')->once()->with(
        'Failed to record request metrics.', Mockery::on(fn (array $context) => $context['exception'] === $failure)
    );
})->with([
    ['getOrRegisterCounter', new RuntimeException('counter storage failed')],
    ['getOrRegisterCounter', new Error('counter failed')],
    ['getOrRegisterHistogram', new RuntimeException('histogram storage failed')],
]);

it('preserves error responses when the registry cannot be resolved', function () {
    $failure = new RuntimeException('registry unavailable');
    $this->app->bind(CollectorRegistry::class, fn () => throw $failure);
    Log::spy();
    $this->postJson('/api/resources', [])->assertUnauthorized()->assertJsonPath('code', 'unauthorized');
    Log::shouldHaveReceived('warning')->once();
});
