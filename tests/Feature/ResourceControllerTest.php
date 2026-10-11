<?php

use App\Models\Resource;
use Illuminate\Support\Facades\Schema;

it('creates a resource by identity alone with 201 and finds it with 200', function () {
    $this->withHeaders(apiKeyHeader());
    $payload = ['external_type' => 'inspector', 'external_id' => 123];
    $created = $this->postJson('/api/resources', $payload)->assertCreated()->assertJsonPath('created', true);
    $found = $this->postJson('/api/resources', $payload)->assertOk()->assertJsonPath('created', false);
    expect($created->json('id'))->toBe($found->json('id'));
    expect(array_keys($created->json()))->toEqualCanonicalizing(['id', 'external_type', 'external_id', 'created']);
    $this->assertDatabaseCount('resources', 1);
});

it('validates resource creation fields', function (array $payload, string $field) {
    $this->withHeaders(apiKeyHeader())->postJson('/api/resources', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($field)->assertJsonPath('code', 'validation_failed');
    $this->assertDatabaseCount('resources', 0);
})->with([
    [[], 'external_type'],
    [['external_type' => 'inspector', 'external_id' => 'bad'], 'external_id'],
    [['external_type' => 7, 'external_id' => 1], 'external_type'],
]);

it('ignores obsolete timezone input when finding a resource', function () {
    $resource = Resource::factory()->create();
    $before = $resource->getAttributes();
    $this->withHeaders(apiKeyHeader())->postJson('/api/resources', [
        'external_type' => $resource->external_type, 'external_id' => $resource->external_id, 'timezone' => 'not/a/zone',
    ])->assertOk()->assertJsonMissingPath('timezone')->assertJsonPath('created', false);
    expect($resource->fresh()->getAttributes())->toEqual($before);
});

it('removes resource timezone storage and the PUT endpoint', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader())->putJson("/api/resources/{$resource->id}", ['timezone' => 'UTC'])
        ->assertNotFound()->assertJsonPath('code', 'route_not_found');
    expect(Schema::hasColumn('resources', 'timezone'))->toBeFalse();
});
