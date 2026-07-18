<?php

use App\Models\BlackoutDate;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a full-day blackout with no start or end time', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/blackout-dates", [
        'date' => '2026-07-21',
        'reason' => 'Holiday',
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('blackout_dates', [
        'resource_id' => $resource->id,
        'date' => '2026-07-21',
        'start_time' => null,
        'end_time' => null,
    ]);
});

it('creates a partial-day blackout with start and end time', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/blackout-dates", [
        'date' => '2026-07-21',
        'start_time' => '12:00',
        'end_time' => '13:00',
        'reason' => 'Lunch',
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
