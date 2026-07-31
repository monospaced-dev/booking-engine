<?php

use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a booking successfully with 201', function () {
    $resource = Resource::factory()->create();

    $response = $this->withHeaders(apiKeyHeader())
        ->postJson("/api/resources/{$resource->id}/bookings", [
            'starts_at' => '2026-07-14T09:00:00Z',
            'ends_at' => '2026-07-14T10:00:00Z',
        ]);

    $response->assertStatus(201);
});

it('returns 409 when a booking overlaps an existing one', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T09:00:00Z',
        'ends_at' => '2026-07-14T10:00:00Z',
    ]);

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T09:30:00Z',
        'ends_at' => '2026-07-14T10:30:00Z',
    ]);

    $response->assertStatus(409);
});

it('allows a back-to-back booking with no gap as 201', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T09:00:00Z',
        'ends_at' => '2026-07-14T10:00:00Z',
    ]);

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T10:00:00Z',
        'ends_at' => '2026-07-14T11:00:00Z',
    ]);

    $response->assertStatus(201);
});

it('returns 409 when a shorter booking is fully nested inside an existing longer one', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T09:00:00Z',
        'ends_at' => '2026-07-14T11:00:00Z', // 2-hour booking
    ]);

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T09:30:00Z',
        'ends_at' => '2026-07-14T10:30:00Z', // 1-hour booking, fully inside the first
    ]);

    $response->assertStatus(409);
});

it('returns 409 when a longer booking fully contains an existing shorter one', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T09:30:00Z',
        'ends_at' => '2026-07-14T10:30:00Z', // 1-hour booking first
    ]);

    $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T09:00:00Z',
        'ends_at' => '2026-07-14T11:00:00Z', // 2-hour booking that swallows it
    ]);

    $response->assertStatus(409);
});

