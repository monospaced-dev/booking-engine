<?php

use App\Models\Booking;
use App\Models\Resource;
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

it('persists the submitted idempotency_key on the created booking row', function () {
    $key = (string) Str::uuid();

    $response = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ], ['Idempotency-Key' => $key]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('bookings', [
        'resource_id' => $this->resource->id,
        'idempotency_key' => $key,
    ]);
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

it('rejects invalid idempotency headers before writing', function (string $key) {
    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z',
        'ends_at' => '2026-08-03T15:00:00Z',
    ], ['Idempotency-Key' => $key])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('Idempotency-Key')
        ->assertOpenApi('/resources/{resource}/bookings', 'post');

    $this->assertDatabaseCount('bookings', 0);
})->with(['empty' => '', '129 characters' => str_repeat('a', 129), 'oversized' => str_repeat('b', 300),
    'space' => 'a b', 'punctuation' => 'key!', 'unicode' => 'café', 'newline' => "key\n"]);

it('accepts boundary idempotency keys including zero', function (string $key) {
    $payload = ['starts_at' => '2026-08-03T14:00:00Z', 'ends_at' => '2026-08-03T15:00:00Z'];
    $first = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", $payload, ['Idempotency-Key' => $key]);
    $first->assertCreated()->assertJsonPath('idempotency_key', $key);
    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", $payload, ['Idempotency-Key' => $key])
        ->assertOk()->assertExactJson($first->json());
    $this->assertDatabaseCount('bookings', 1);
})->with(['zero' => '0', 'one character' => '_', '128 characters' => str_repeat('Az0_-', 25).'ABC']);

it('rejects a replay whose stored payload differs', function (array $changes) {
    $payload = ['starts_at' => '2026-08-03T14:00:00Z', 'ends_at' => '2026-08-03T15:00:00Z', 'metadata' => ['appointment_id' => 7]];
    $first = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", $payload, ['Idempotency-Key' => 'payload-key']);
    $first->assertCreated();
    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", array_replace($payload, $changes), ['Idempotency-Key' => 'payload-key'])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_mismatch')
        ->assertOpenApi('/resources/{resource}/bookings', 'post');
    $stored = $this->withHeaders($this->headers)->getJson("/api/resources/{$this->resource->id}/bookings");
    $stored->assertExactJson([$first->json()]);
})->with([
    'start' => [['starts_at' => '2026-08-03T14:10:00Z']],
    'end' => [['ends_at' => '2026-08-03T15:10:00Z']],
    'metadata' => [['metadata' => ['appointment_id' => 8]]],
    'removed metadata' => [['metadata' => []]],
]);

it('compares UTC instants and the currently retained metadata', function () {
    $first = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T14:00:00Z', 'ends_at' => '2026-08-03T15:00:00Z',
        'metadata' => ['appointment_id' => 7, 'ignored' => 'first'],
    ], ['Idempotency-Key' => 'offset-key']);
    $first->assertCreated();
    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", [
        'starts_at' => '2026-08-03T09:00:00-05:00', 'ends_at' => '2026-08-03T17:00:00+02:00',
        'metadata' => ['ignored' => 'second', 'appointment_id' => 7],
    ], ['Idempotency-Key' => 'offset-key'])->assertOk()->assertExactJson($first->json())
        ->assertOpenApi('/resources/{resource}/bookings', 'post');
});

it('compares absent and empty metadata as the existing empty stored payload', function () {
    $payload = ['starts_at' => '2026-08-03T14:00:00Z', 'ends_at' => '2026-08-03T15:00:00Z'];
    $first = $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", $payload, ['Idempotency-Key' => 'empty-metadata']);
    $this->withHeaders($this->headers)->postJson("/api/resources/{$this->resource->id}/bookings", $payload + ['metadata' => []], ['Idempotency-Key' => 'empty-metadata'])
        ->assertOk()->assertExactJson($first->json());
});
