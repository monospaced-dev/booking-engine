<?php

use App\Actions\CreateBooking;
use App\Models\BlackoutDate;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use Tests\Support\OpenApi;

it('loads a valid hand-written OpenAPI document', function () {
    $schema = OpenApi::validator()->getSchema();
    expect($schema->validate())->toBeTrue();
    expect($schema->getErrors())->toBe([]);
});

it('validates real booking create replay list conflict and delete responses', function (array $metadata) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/bookings";
    $operation = '/resources/{resource}/bookings';
    $payload = ['starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T10:00:00Z', 'metadata' => $metadata];
    $created = $this->postJson($path, $payload, ['Idempotency-Key' => 'spec-booking'])
        ->assertCreated()->assertOpenApi($operation, 'post');
    $replayed = $this->postJson($path, $payload, ['Idempotency-Key' => 'spec-booking'])
        ->assertOk()->assertOpenApi($operation, 'post');
    expect($replayed->json())->toEqual($created->json());
    $this->getJson($path)->assertOk()->assertOpenApi($operation, 'get');
    $conflict = $this->postJson($path, $payload)->assertConflict()->assertOpenApi($operation, 'post');
    expect($conflict->json('error'))->toBe($conflict->json('message'));
    $this->deleteJson($path.'/'.$created->json('id'))->assertNoContent()
        ->assertOpenApi('/resources/{resource}/bookings/{booking}', 'delete');
    $this->deleteJson($path.'/'.$created->json('id'))->assertNotFound()
        ->assertOpenApi('/resources/{resource}/bookings/{booking}', 'delete');
})->with(['empty metadata' => [[]], 'populated metadata' => [['appointment_id' => 7]]]);

it('validates resource create find and update responses', function () {
    $this->withHeaders(apiKeyHeader());
    $payload = ['external_type' => 'inspector', 'external_id' => 7, 'timezone' => 'UTC'];
    $created = $this->postJson('/api/resources', $payload)->assertCreated()->assertOpenApi('/resources', 'post');
    $this->postJson('/api/resources', $payload)->assertOk()->assertOpenApi('/resources', 'post');
    $this->putJson('/api/resources/'.$created->json('id'), ['timezone' => 'America/Chicago'])
        ->assertOk()->assertOpenApi('/resources/{resource}', 'put');
});

it('validates blackout create list and delete responses', function (array $payload) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/blackout-dates";
    $operation = '/resources/{resource}/blackout-dates';
    $this->getJson($path)->assertOk()->assertExactJson([])->assertOpenApi($operation, 'get');
    $created = $this->postJson($path, $payload)->assertCreated()->assertOpenApi($operation, 'post');
    $this->getJson($path)->assertOk()->assertOpenApi($operation, 'get');
    $this->deleteJson($path.'/'.$created->json('id'))->assertNoContent()
        ->assertOpenApi('/resources/{resource}/blackout-dates/{blackoutDate}', 'delete');
    $this->deleteJson($path.'/'.$created->json('id'))->assertNotFound()
        ->assertOpenApi('/resources/{resource}/blackout-dates/{blackoutDate}', 'delete');
})->with([
    'full day' => [['date' => '2026-07-21']],
    'partial day' => [['date' => '2026-07-21', 'start_time' => '12:00', 'end_time' => '13:00', 'note' => 'Lunch']],
]);

it('validates populated and empty busy responses', function () {
    $resource = Resource::factory()->create(['timezone' => 'UTC']);
    $this->withHeaders(apiKeyHeader());
    $uri = "/api/resources/{$resource->id}/busy?".http_build_query([
        'from' => '2026-07-21T00:00:00Z', 'to' => '2026-07-22T00:00:00Z',
    ]);
    $this->getJson($uri)->assertOk()->assertExactJson([])->assertOpenApi('/resources/{resource}/busy', 'get');
    BlackoutDate::factory()->create(['resource_id' => $resource->id, 'date' => '2026-07-21', 'start_time' => null, 'end_time' => null]);
    $this->getJson($uri)->assertOk()->assertJsonCount(1)->assertOpenApi('/resources/{resource}/busy', 'get');
});

it('validates unauthenticated probe responses', function () {
    $this->getJson('/api/health')->assertOk()->assertOpenApi('/health', 'get');
    $this->getJson('/api/ping')->assertNoContent()->assertOpenApi('/ping', 'get');
});

it('validates the database-unavailable probe response', function () {
    DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('unavailable'));
    $this->getJson('/api/health')->assertStatus(503)->assertOpenApi('/health', 'get');
});

it('validates 401 on every protected operation', function (string $method, string $operation) {
    $resource = Resource::factory()->create();
    $booking = (new CreateBooking)->handle($resource->id, $resource->external_type, $resource->external_id,
        Carbon::parse('2026-07-21T09:00:00Z'), Carbon::parse('2026-07-21T10:00:00Z'));
    $blackout = BlackoutDate::factory()->create(['resource_id' => $resource->id]);
    $uri = '/api'.strtr($operation, ['{resource}' => $resource->id, '{booking}' => $booking->id, '{blackoutDate}' => $blackout->id]);
    $this->json($method, $uri)->assertUnauthorized()->assertOpenApi($operation, $method);
})->with([
    ['post', '/resources'], ['put', '/resources/{resource}'],
    ['get', '/resources/{resource}/bookings'], ['post', '/resources/{resource}/bookings'],
    ['delete', '/resources/{resource}/bookings/{booking}'], ['get', '/resources/{resource}/busy'],
    ['get', '/resources/{resource}/blackout-dates'], ['post', '/resources/{resource}/blackout-dates'],
    ['delete', '/resources/{resource}/blackout-dates/{blackoutDate}'],
]);

it('validates missing-resource 404 on every resource-bound operation', function (string $method, string $operation) {
    $this->withHeaders(apiKeyHeader());
    $uri = '/api'.strtr($operation, ['{resource}' => 999999, '{booking}' => 999999, '{blackoutDate}' => 999999]);
    $this->json($method, $uri)->assertNotFound()->assertOpenApi($operation, $method);
})->with([
    ['put', '/resources/{resource}'], ['get', '/resources/{resource}/bookings'],
    ['post', '/resources/{resource}/bookings'], ['delete', '/resources/{resource}/bookings/{booking}'],
    ['get', '/resources/{resource}/busy'], ['get', '/resources/{resource}/blackout-dates'],
    ['post', '/resources/{resource}/blackout-dates'], ['delete', '/resources/{resource}/blackout-dates/{blackoutDate}'],
]);

it('validates 422 on every operation accepting validated inputs', function (string $method, string $operation) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $uri = '/api'.strtr($operation, ['{resource}' => $resource->id]);
    if ($method === 'get' && str_ends_with($operation, '/bookings')) {
        $uri .= '?from=invalid';
    }
    $this->json($method, $uri)->assertUnprocessable()->assertOpenApi($operation, $method);
})->with([
    ['post', '/resources'], ['put', '/resources/{resource}'], ['get', '/resources/{resource}/bookings'],
    ['post', '/resources/{resource}/bookings'], ['get', '/resources/{resource}/busy'],
    ['post', '/resources/{resource}/blackout-dates'],
]);

it('rejects regressions in captured real responses', function (string $mutation) {
    $resource = Resource::factory()->create();
    $response = $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T10:00:00Z',
    ])->assertCreated();
    $payload = $response->json();
    match ($mutation) {
        'missing timestamp' => $payload = array_diff_key($payload, ['created_at' => true]),
        'legacy timestamp' => $payload['starts_at'] = '2026-07-21T09:00:00.000000Z',
        'wrong type' => $payload['id'] = 'wrong',
    };
    $response->baseResponse->setContent(json_encode($payload));
    expect(fn () => OpenApi::assertResponse($response, '/resources/{resource}/bookings', 'post'))
        ->toThrow(ValidationFailed::class);
})->with(['missing timestamp', 'legacy timestamp', 'wrong type']);

it('validates the default error envelope on an unexpected controller failure', function () {
    $resource = Resource::factory()->create();
    $this->mock(CreateBooking::class)->shouldReceive('handle')->once()
        ->andThrow(new RuntimeException('unexpected failure'));
    $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T10:00:00Z',
    ])->assertStatus(500)->assertJsonPath('code', 'internal_error')
        ->assertOpenApi('/resources/{resource}/bookings', 'post');
});

it('rejects a legacy conflict envelope lacking message or code', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/bookings";
    $payload = ['starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T10:00:00Z'];
    $this->postJson($path, $payload)->assertCreated();
    $response = $this->postJson($path, $payload)->assertConflict();
    $response->baseResponse->setContent(json_encode(['error' => $response->json('error')]));
    expect(fn () => OpenApi::assertResponse($response, '/resources/{resource}/bookings', 'post'))
        ->toThrow(ValidationFailed::class);
});
