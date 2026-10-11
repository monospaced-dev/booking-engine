<?php

use App\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('adds an unauthorized code without requiring an Accept header', function () {
    $resource = Resource::factory()->create();
    $this->get("/api/resources/{$resource->id}/bookings")->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthorized')->assertJsonPath('code', 'unauthorized');
});

it('keeps the conflict error and adds message and code', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $payload = ['starts_at' => '2026-07-14T09:00:00Z', 'ends_at' => '2026-07-14T10:00:00Z'];
    $this->postJson("/api/resources/{$resource->id}/bookings", $payload)->assertCreated();
    $response = $this->postJson("/api/resources/{$resource->id}/bookings", $payload)->assertConflict()
        ->assertJsonPath('code', 'booking_conflict');
    expect($response->json('message'))->toBe($response->json('error'))->toBeString();
});

it('adds a validation code and preserves field errors', function () {
    $this->withHeaders(apiKeyHeader())->postJson('/api/resources', [])->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['external_type', 'external_id', 'timezone']);
});

it('normalizes framework errors including unexpected failures', function (int $status, string $code) {
    Route::get('/api/contract-error-probe', fn () => abort($status));
    $this->getJson('/api/contract-error-probe')->assertStatus($status)->assertJsonPath('code', $code)
        ->assertJsonStructure(['message', 'code']);
})->with([[403, 'forbidden'], [405, 'method_not_allowed'], [429, 'too_many_requests'], [500, 'internal_error']]);

it('adds an envelope to the unhealthy database response preserving probe fields', function () {
    DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('unavailable'));
    $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('code', 'database_unavailable')
        ->assertJsonPath('database', 'unreachable')->assertJsonPath('status', 'error')->assertJsonStructure(['message']);
});
