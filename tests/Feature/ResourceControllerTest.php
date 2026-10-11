<?php

use App\Models\Resource;

it('creates a resource with 201 and finds it with 200', function () {
    $this->withHeaders(apiKeyHeader());
    $payload = ['external_type' => 'inspector', 'external_id' => 123, 'timezone' => 'America/Chicago'];
    $created = $this->postJson('/api/resources', $payload)->assertCreated()->assertJsonPath('created', true);
    $found = $this->postJson('/api/resources', $payload)->assertOk()->assertJsonPath('created', false);
    expect($created->json('id'))->toBe($found->json('id'));
    $this->assertDatabaseCount('resources', 1);
});

it('updates resource timezone and retains external identity', function () {
    $resource = Resource::factory()->create(['timezone' => 'America/Chicago']);
    $this->withHeaders(apiKeyHeader())->putJson("/api/resources/{$resource->id}", ['timezone' => 'Asia/Tokyo'])
        ->assertOk()->assertExactJson(['id' => $resource->id, 'external_type' => $resource->external_type,
            'external_id' => $resource->external_id, 'timezone' => 'Asia/Tokyo']);
    expect($resource->fresh()->timezone)->toBe('Asia/Tokyo');
});

it('validates resource creation fields', function (array $payload, string $field) {
    $this->withHeaders(apiKeyHeader())->postJson('/api/resources', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($field)->assertJsonPath('code', 'validation_failed');
    $this->assertDatabaseCount('resources', 0);
})->with([
    [[], 'external_type'],
    [['external_type' => 'inspector', 'external_id' => 'bad', 'timezone' => 'UTC'], 'external_id'],
    [['external_type' => 'inspector', 'external_id' => 1, 'timezone' => 'not/a/zone'], 'timezone'],
]);

it('rejects missing or invalid timezone updates without changing the resource', function (array $payload) {
    $resource = Resource::factory()->create(['timezone' => 'America/Chicago']);
    $this->withHeaders(apiKeyHeader())->putJson("/api/resources/{$resource->id}", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('timezone');
    expect($resource->fresh()->timezone)->toBe('America/Chicago');
})->with([[[]], [['timezone' => 'not/a/zone']]]);

it('returns 404 updating a missing resource', function () {
    $this->withHeaders(apiKeyHeader())->putJson('/api/resources/999999', ['timezone' => 'UTC'])
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});
