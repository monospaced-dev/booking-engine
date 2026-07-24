<?php

use App\Models\Booking;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create();
    $this->headers = apiKeyHeader();
});

it('creates a new booking when no idempotency key is sent', function () {
    $response = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseCount('bookings', 1);
});

it('replays the existing booking on a duplicate idempotency key instead of creating a new one', function () {
    $key = (string) Str::uuid();

    $first = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ], ['Idempotency-Key' => $key]);

    $first->assertStatus(201);

    $second = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ], ['Idempotency-Key' => $key]);

    $second->assertStatus(200);
    expect($second->json('id'))->toBe($first->json('id'));

    $this->assertDatabaseCount('bookings', 1);
});

it('allows the same idempotency key to be reused across different resources', function () {
    $otherResource = Resource::factory()->create();
    $key = (string) Str::uuid();

    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ], ['Idempotency-Key' => $key])->assertStatus(201);

    $this->withHeaders($this->headers)->postJson("/api/resources/{$otherResource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ], ['Idempotency-Key' => $key])->assertStatus(201);

    $this->assertDatabaseCount('bookings', 2);
});

it('still returns 409 for a genuine overlapping booking regardless of idempotency key', function () {
    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);

    $response = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:30:00Z', // overlaps the first booking
        'ends_at' => '2026-08-03T15:30:00Z',
    ], ['Idempotency-Key' => (string) Str::uuid()]); // different key — genuinely new attempt

    $response->assertStatus(409);
    $this->assertDatabaseCount('bookings', 1);
});

it('allows multiple bookings with no idempotency key on the same resource at different times', function () {
    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ])->assertStatus(201);

    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T16:00:00Z',
        'ends_at' => '2026-08-03T17:00:00Z',
    ])->assertStatus(201);

    $this->assertDatabaseCount('bookings', 2);
});
