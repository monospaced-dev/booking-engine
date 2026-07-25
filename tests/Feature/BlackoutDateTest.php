<?php

use App\Models\BlackoutDate;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns blackout dates for the resource ordered by date ascending', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    BlackoutDate::factory()->create([
        'resource_id' => $resource->id,
        'date' => '2026-07-25',
        'note' => 'Later one',
    ]);
    BlackoutDate::factory()->create([
        'resource_id' => $resource->id,
        'date' => '2026-07-21',
        'note' => 'Earlier one',
    ]);

    $response = $this->withHeaders($headers)->getJson("/api/resources/{$resource->id}/blackout-dates");

    $response->assertStatus(200);
    expect($response->json('*.date'))->toBe(['2026-07-21', '2026-07-25']);
});

it('does not return blackout dates belonging to a different resource', function () {
    $resourceA = Resource::factory()->create();
    $resourceB = Resource::factory()->create();
    $headers = apiKeyHeader();

    BlackoutDate::factory()->create(['resource_id' => $resourceA->id, 'date' => '2026-07-21']);
    $blackoutB = BlackoutDate::factory()->create(['resource_id' => $resourceB->id, 'date' => '2026-07-22']);

    $response = $this->withHeaders($headers)->getJson("/api/resources/{$resourceB->id}/blackout-dates");

    $response->assertStatus(200);
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($blackoutB->id);
});

it('returns an empty array when the resource has no blackout dates', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $response = $this->withHeaders($headers)->getJson("/api/resources/{$resource->id}/blackout-dates");

    $response->assertStatus(200);
    expect($response->json())->toBe([]);
});

it('serializes note whether null or set', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    BlackoutDate::factory()->create(['resource_id' => $resource->id, 'date' => '2026-07-21', 'note' => 'Holiday']);
    BlackoutDate::factory()->create(['resource_id' => $resource->id, 'date' => '2026-07-22', 'note' => null]);

    $response = $this->withHeaders($headers)->getJson("/api/resources/{$resource->id}/blackout-dates");

    $response->assertStatus(200);
    expect($response->json('*.note'))->toBe(['Holiday', null]);
});

it('creates a full-day blackout with no start or end time', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/blackout-dates", [
        'date' => '2026-07-21',
        'note' => 'Holiday',
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('blackout_dates', [
        'resource_id' => $resource->id,
        'date' => '2026-07-21',
        'start_time' => null,
        'end_time' => null,
        'note' => 'Holiday',
    ]);
});

it('creates a partial-day blackout with start and end time', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/blackout-dates", [
        'date' => '2026-07-21',
        'start_time' => '12:00',
        'end_time' => '13:00',
        'note' => 'Lunch',
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('blackout_dates', [
        'resource_id' => $resource->id,
        'date' => '2026-07-21',
    ]);
});

it('returns 422 when start_time is given without end_time', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/blackout-dates", [
        'date' => '2026-07-21',
        'start_time' => '12:00',
    ]);

    $response->assertStatus(422);
});

it('returns 422 when end_time is given without start_time', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/blackout-dates", [
        'date' => '2026-07-21',
        'end_time' => '13:00',
    ]);

    $response->assertStatus(422);
});

it('deletes a blackout date belonging to the resource', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $blackout = BlackoutDate::factory()->create([
        'resource_id' => $resource->id,
        'date' => '2026-07-21',
        'start_time' => null,
        'end_time' => null,
    ]);

    $response = $this->withHeaders($headers)
        ->deleteJson("/api/resources/{$resource->id}/blackout-dates/{$blackout->id}");

    $response->assertStatus(204);
    $this->assertDatabaseMissing('blackout_dates', ['id' => $blackout->id]);
});

it('returns 403 when deleting a blackout date belonging to a different resource', function () {
    $resourceA = Resource::factory()->create();
    $resourceB = Resource::factory()->create();
    $headers = apiKeyHeader();

    $blackout = BlackoutDate::factory()->create([
        'resource_id' => $resourceA->id,
        'date' => '2026-07-21',
        'start_time' => null,
        'end_time' => null,
    ]);

    $response = $this->withHeaders($headers)
        ->deleteJson("/api/resources/{$resourceB->id}/blackout-dates/{$blackout->id}");

    $response->assertStatus(403);
    $this->assertDatabaseHas('blackout_dates', ['id' => $blackout->id]);
});
