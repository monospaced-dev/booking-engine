<?php

use App\Actions\CreateBooking;
use App\Models\Blackout;
use App\Models\Resource;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\DB;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
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

it('validates resource create and find responses', function () {
    $this->withHeaders(apiKeyHeader());
    $payload = ['external_type' => 'inspector', 'external_id' => 7];
    $created = $this->postJson('/api/resources', $payload)->assertCreated()->assertOpenApi('/resources', 'post');
    $this->postJson('/api/resources', $payload)->assertOk()->assertOpenApi('/resources', 'post');

});

it('validates blackout create list and delete responses', function (array $payload) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/blackouts";
    $operation = '/resources/{resource}/blackouts';
    $this->getJson($path)->assertOk()->assertExactJson([])->assertOpenApi($operation, 'get');
    $created = $this->postJson($path, $payload)->assertCreated()->assertOpenApi($operation, 'post');
    $this->getJson($path)->assertOk()->assertOpenApi($operation, 'get');
    $this->deleteJson($path.'/'.$created->json('id'))->assertNoContent()
        ->assertOpenApi('/resources/{resource}/blackouts/{blackout}', 'delete');
    $this->deleteJson($path.'/'.$created->json('id'))->assertNotFound()
        ->assertOpenApi('/resources/{resource}/blackouts/{blackout}', 'delete');
})->with([
    'full day' => [['starts_at' => '2026-07-21T00:00:00Z', 'ends_at' => '2026-07-22T00:00:00Z']],
    'partial day' => [['starts_at' => '2026-07-21T12:00:00Z', 'ends_at' => '2026-07-21T13:00:00Z', 'note' => 'Lunch']],
]);

it('validates populated and empty busy responses', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $uri = "/api/resources/{$resource->id}/busy?".http_build_query([
        'from' => '2026-07-21T00:00:00Z', 'to' => '2026-07-22T00:00:00Z',
    ]);
    $this->getJson($uri)->assertOk()->assertExactJson([])->assertOpenApi('/resources/{resource}/busy', 'get');
    Blackout::factory()->create(['resource_id' => $resource->id, 'during' => '[2026-07-21T00:00:00Z,2026-07-22T00:00:00Z)']);
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
    $blackout = Blackout::factory()->create(['resource_id' => $resource->id]);
    $uri = '/api'.strtr($operation, ['{resource}' => $resource->id, '{booking}' => $booking->id, '{blackout}' => $blackout->id]);
    $this->json($method, $uri)->assertUnauthorized()->assertOpenApi($operation, $method);
})->with([
    ['post', '/busy'], ['post', '/resources'],
    ['get', '/resources/{resource}/bookings'], ['post', '/resources/{resource}/bookings'],
    ['get', '/resources/{resource}/bookings/{booking}'], ['patch', '/resources/{resource}/bookings/{booking}'],
    ['delete', '/resources/{resource}/bookings/{booking}'], ['get', '/resources/{resource}/busy'],
    ['get', '/resources/{resource}/blackouts'], ['post', '/resources/{resource}/blackouts'],
    ['delete', '/resources/{resource}/blackouts/{blackout}'],
]);

it('validates missing-resource 404 on every resource-bound operation', function (string $method, string $operation) {
    $this->withHeaders(apiKeyHeader());
    $uri = '/api'.strtr($operation, ['{resource}' => 999999, '{booking}' => 999999, '{blackout}' => 999999]);
    $this->json($method, $uri)->assertNotFound()->assertOpenApi($operation, $method);
})->with([
    ['get', '/resources/{resource}/bookings'],
    ['post', '/resources/{resource}/bookings'], ['get', '/resources/{resource}/bookings/{booking}'], ['patch', '/resources/{resource}/bookings/{booking}'], ['delete', '/resources/{resource}/bookings/{booking}'],
    ['get', '/resources/{resource}/busy'], ['get', '/resources/{resource}/blackouts'],
    ['post', '/resources/{resource}/blackouts'], ['delete', '/resources/{resource}/blackouts/{blackout}'],
]);

it('validates 422 on every operation accepting validated inputs', function (string $method, string $operation) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $uri = '/api'.strtr($operation, ['{resource}' => $resource->id]);
    if ($method === 'get' && (str_ends_with($operation, '/bookings') || str_ends_with($operation, '/blackouts'))) {
        $uri .= '?from=invalid';
    }
    $this->json($method, $uri)->assertUnprocessable()->assertOpenApi($operation, $method);
})->with([
    ['post', '/busy'], ['post', '/resources'], ['get', '/resources/{resource}/bookings'],
    ['post', '/resources/{resource}/bookings'], ['get', '/resources/{resource}/busy'],
    ['post', '/resources/{resource}/blackouts'], ['get', '/resources/{resource}/blackouts'],
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

it('rejects missing or malformed booking version response contracts', function (string $mutation) {
    $resource = Resource::factory()->create();
    $response = $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T10:00:00Z',
    ])->assertCreated();
    if ($mutation === 'missing version') {
        $payload = $response->json();
        unset($payload['lock_version']);
        $response->baseResponse->setContent(json_encode($payload));
    } elseif ($mutation === 'missing ETag') {
        $response->headers->remove('ETag');
    } else {
        $response->headers->set('ETag', '1');
    }
    expect(fn () => OpenApi::assertResponse($response, '/resources/{resource}/bookings', 'post'))
        ->toThrow(ValidationFailed::class);
})->with(['missing version', 'missing ETag', 'unquoted ETag']);

it('validates the PATCH If-Match request contract', function (?string $tag) {
    $validator = (new ValidatorBuilder)
        ->fromYamlFile(base_path('docs/openapi.yaml'))->getRequestValidator();
    $headers = ['Content-Type' => 'application/json', 'X-API-Key' => 'spec-key'];
    if ($tag !== null) {
        $headers['If-Match'] = $tag;
    }
    $request = new Request('PATCH', '/api/resources/1/bookings/1', $headers, json_encode([
        'starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T10:00:00Z',
    ]));
    if ($tag === '"1"') {
        expect($validator->validate($request)->method())->toBe('patch');
    } else {
        expect(fn () => $validator->validate($request))->toThrow(ValidationFailed::class);
    }
})->with(['"1"', null, '1', 'W/"1"', '*']);

it('validates integer path IDs with their documented bounds', function (string $id, bool $valid) {
    $validator = (new ValidatorBuilder)->fromYamlFile(base_path('docs/openapi.yaml'))->getRequestValidator();
    $request = new Request('DELETE', "/api/resources/{$id}/blackouts/{$id}", ['X-API-Key' => 'spec-key']);
    if ($valid) {
        expect($validator->validate($request)->method())->toBe('delete');
    } else {
        expect(fn () => $validator->validate($request))->toThrow(ValidationFailed::class);
    }
})->with([['1', true], ['999999999999999999', true], ['0', false], ['1000000000000000000', false], ['-1', false], ['abc', false]]);

it('validates the batch busy request schema', function (array $overrides, bool $valid) {
    $validator = (new ValidatorBuilder)->fromYamlFile(base_path('docs/openapi.yaml'))->getRequestValidator();
    $request = new Request('POST', '/api/busy', ['Content-Type' => 'application/json', 'X-API-Key' => 'spec-key'], json_encode(array_replace([
        'resource_ids' => [3, 1, 3], 'from' => '2026-07-21T00:00:00Z', 'to' => '2026-07-22T00:00:00Z',
    ], $overrides)));
    if ($valid) {
        expect($validator->validate($request)->path())->toBe('/busy');
    } else {
        expect(fn () => $validator->validate($request))->toThrow(ValidationFailed::class);
    }
})->with([
    'duplicates allowed' => [[], true],
    '100 ids' => [['resource_ids' => range(1, 100)], true],
    'empty ids' => [['resource_ids' => []], false],
    '101 ids' => [['resource_ids' => range(1, 101)], false],
    'noninteger' => [['resource_ids' => ['stylist']], false],
    'missing from' => [['from' => null], false],
    'naive to' => [['to' => '2026-07-22T00:00:00'], false],
]);

it('rejects batch response contract regressions', function (string $mutation) {
    $id = Resource::factory()->create()->id;
    Blackout::factory()->create(['resource_id' => $id, 'during' => '[2026-07-21T10:00:00Z,2026-07-21T11:00:00Z)']);
    $response = $this->withHeaders(apiKeyHeader())->postJson('/api/busy', [
        'resource_ids' => [$id], 'from' => '2026-07-21T00:00:00Z', 'to' => '2026-07-22T00:00:00Z',
    ])->assertOk()->assertOpenApi('/busy', 'post');
    $payload = $response->json();
    match ($mutation) {
        'availability field' => $payload['available'] = true,
        'range extra field' => $payload['busy'][$id][0]['free'] = false,
        'legacy timestamp' => $payload['busy'][$id][0]['starts_at'] = '2026-07-21T10:00:00.000000Z',
        'missing wrapper' => $payload = $payload['busy'],
    };
    $response->baseResponse->setContent(json_encode($payload));
    expect(fn () => OpenApi::assertResponse($response, '/busy', 'post'))->toThrow(ValidationFailed::class);
})->with(['availability field', 'range extra field', 'legacy timestamp', 'missing wrapper']);
