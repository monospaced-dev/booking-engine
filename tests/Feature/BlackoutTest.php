<?php

use App\Models\Resource;
use Carbon\Carbon;

function blackoutPayload(): array
{
    return ['starts_at' => '2026-07-21T23:00:00-05:00', 'ends_at' => '2026-07-23T01:00:00-05:00', 'note' => 'Maintenance'];
}

it('creates complete UTC blackout objects and lists all or overlapping unclipped rows', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/blackouts";
    $created = $this->postJson($path, blackoutPayload())->assertCreated()->assertOpenApi('/resources/{resource}/blackouts', 'post');
    expect(array_keys($created->json()))->toEqualCanonicalizing(['id', 'resource_id', 'starts_at', 'ends_at', 'note', 'created_at', 'updated_at']);
    $created->assertJsonPath('resource_id', $resource->id)->assertJsonPath('starts_at', '2026-07-22T04:00:00+00:00')
        ->assertJsonPath('ends_at', '2026-07-23T06:00:00+00:00')->assertJsonPath('note', 'Maintenance');
    foreach (['created_at', 'updated_at'] as $field) {
        expect($created->json($field))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/');
    }
    $other = Resource::factory()->create();
    $this->postJson("/api/resources/{$other->id}/blackouts", blackoutPayload())->assertCreated();
    $this->getJson($path)->assertExactJson([$created->json()])->assertOpenApi('/resources/{resource}/blackouts', 'get');
    foreach ([
        ['2026-07-22T05:00:00Z', '2026-07-22T06:00:00Z', 1],
        ['2026-07-22T00:00:00Z', '2026-07-22T04:00:00Z', 0],
        ['2026-07-23T06:00:00Z', '2026-07-24T00:00:00Z', 0],
        ['2026-07-22T00:00:00-05:00', '2026-07-22T01:00:00-05:00', 1],
    ] as [$from, $to, $count]) {
        $this->getJson($path.'?'.http_build_query(compact('from', 'to')))->assertExactJson($count ? [$created->json()] : []);
    }
});

it('validates blackout creation without writing', function (array $payload, string $field) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/blackouts", $payload)
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field)->assertOpenApi('/resources/{resource}/blackouts', 'post');
    $this->assertDatabaseCount('blackouts', 0);
})->with([
    [[], 'starts_at'],
    [['starts_at' => '2026-07-21T09:00:00Z'], 'ends_at'],
    [['starts_at' => '2026-07-21T09:00:00', 'ends_at' => '2026-07-21T10:00:00Z'], 'starts_at'],
    [['starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T10:00:00'], 'ends_at'],
    [['starts_at' => 'bad', 'ends_at' => 'bad'], 'starts_at'],
    [['starts_at' => '2026-07-21T09:00:00Z', 'ends_at' => '2026-07-21T09:00:00Z'], 'ends_at'],
    [['starts_at' => '2026-07-21T10:00:00Z', 'ends_at' => '2026-07-21T09:00:00Z'], 'ends_at'],
]);

it('validates optional notes', function (mixed $note) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/blackouts", array_replace(blackoutPayload(), ['note' => $note]))
        ->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->assertDatabaseCount('blackouts', 0);
})->with([str_repeat('a', 256), 7, [['nested']]]);

it('applies the 366 elapsed day limit and defaults note to null', function (int $seconds, int $status) {
    $resource = Resource::factory()->create();
    $start = Carbon::parse('2026-01-01T00:00:00Z');
    $response = $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/blackouts", [
        'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addSeconds($seconds)->toIso8601String(),
    ])->assertStatus($status);
    if ($status === 201) {
        $response->assertJsonPath('note', null);
    } else {
        $response->assertJsonValidationErrors('ends_at');
        $this->assertDatabaseCount('blackouts', 0);
    }
})->with([[366 * 86400, 201], [366 * 86400 + 1, 422]]);

it('validates optional list bounds as a pair with offsets', function (array $window) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader())->getJson("/api/resources/{$resource->id}/blackouts?".http_build_query($window))
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
})->with([
    [['from' => '2026-07-21T00:00:00Z']], [['to' => '2026-07-22T00:00:00Z']],
    [['from' => 'bad', 'to' => 'bad']],
    [['from' => '2026-07-21T00:00:00', 'to' => '2026-07-22T00:00:00Z']],
    [['from' => '2026-07-21T00:00:00Z', 'to' => '2026-07-22T00:00:00']],
    [['from' => '2026-07-22T00:00:00Z', 'to' => '2026-07-21T00:00:00Z']],
    [['from' => '2026-07-21T00:00:00Z', 'to' => '2026-07-21T00:00:00Z']],
]);

it('allows overlapping rows without idempotency or versions and scopes deletes to the parent', function () {
    $resource = Resource::factory()->create();
    $other = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/blackouts";
    $created = $this->postJson($path, blackoutPayload(), ['Idempotency-Key' => 'invalid key'])->assertCreated();
    $again = $this->postJson($path, blackoutPayload(), ['Idempotency-Key' => 'invalid key'])->assertCreated();
    expect($again->json('id'))->not->toBe($created->json('id'));
    $this->deleteJson("/api/resources/{$other->id}/blackouts/".$created->json('id'))->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->assertDatabaseCount('blackouts', 2);
    $this->deleteJson($path.'/'.$created->json('id'))->assertNoContent()->assertOpenApi('/resources/{resource}/blackouts/{blackout}', 'delete');
    $this->deleteJson($path.'/'.$created->json('id'))->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->patchJson($path.'/'.$again->json('id'), blackoutPayload())->assertStatus(405);
});

it('does not reject bookings that overlap blackouts', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/blackouts", blackoutPayload())->assertCreated();
    $this->postJson("/api/resources/{$resource->id}/bookings", blackoutPayload())->assertCreated();
});
