<?php

use App\Models\Resource;

it('returns the full stored booking on create and replay matching GET', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/bookings";
    $payload = ['starts_at' => '2026-07-14T09:00:00Z', 'ends_at' => '2026-07-14T10:00:00Z', 'metadata' => ['appointment_id' => 42]];
    $created = $this->postJson($path, $payload, ['Idempotency-Key' => 'full-response'])->assertCreated()
        ->assertJsonStructure(['id', 'resource_id', 'external_type', 'external_id', 'metadata', 'idempotency_key', 'starts_at', 'ends_at', 'created_at', 'updated_at']);
    $listed = $this->getJson($path)->assertOk();
    $replayed = $this->postJson($path, $payload, ['Idempotency-Key' => 'full-response'])->assertOk();
    expect($created->json())->toEqual($listed->json('0'))->toEqual($replayed->json());
});
