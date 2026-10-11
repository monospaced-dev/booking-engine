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

it('uses UTC seconds precision for every booking and blackout timestamp', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $booking = $this->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T04:00:00-05:00', 'ends_at' => '2026-07-14T05:00:00-05:00',
    ])->assertCreated()->assertJsonPath('starts_at', '2026-07-14T09:00:00+00:00');
    $blackout = $this->postJson("/api/resources/{$resource->id}/blackouts", ['starts_at' => '2026-07-15T00:00:00Z', 'ends_at' => '2026-07-16T00:00:00Z'])->assertCreated();
    foreach ([$booking, $blackout] as $response) {
        foreach (['created_at', 'updated_at'] as $field) {
            expect($response->json($field))->toMatch('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$/');
        }
    }
});
