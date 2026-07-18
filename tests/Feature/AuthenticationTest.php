<?php

use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 401 when no API key is provided', function () {
    $resource = Resource::factory()->create();

    $response = $this->postJson("/api/resources/{$resource->id}/bookings", [
        'starts_at' => '2026-07-14T09:00:00Z',
        'ends_at' => '2026-07-14T10:00:00Z',
    ]);

    $response->assertStatus(401);
});

it('returns 401 when an invalid API key is provided', function () {
    $resource = Resource::factory()->create();

    $response = $this->withHeaders(['X-API-Key' => 'not-a-real-key'])
        ->postJson("/api/resources/{$resource->id}/bookings", [
            'starts_at' => '2026-07-14T09:00:00Z',
            'ends_at' => '2026-07-14T10:00:00Z',
        ]);

    $response->assertStatus(401);
});

it('returns 401 when the API key is marked inactive', function () {
    $plainKey = 'inactive-key-test';

    \App\Models\ApiKey::create([
        'name' => 'inactive-key',
        'key' => \App\Models\ApiKey::hashKey($plainKey),
        'is_active' => false,
    ]);

    $resource = Resource::factory()->create();

    $response = $this->withHeaders(['X-API-Key' => $plainKey])
        ->postJson("/api/resources/{$resource->id}/bookings", [
            'starts_at' => '2026-07-14T09:00:00Z',
            'ends_at' => '2026-07-14T10:00:00Z',
        ]);

    $response->assertStatus(401);
});
