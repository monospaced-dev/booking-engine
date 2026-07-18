<?php

use App\Actions\CreateBooking;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes a booking when it belongs to the resource in the URL', function () {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();

    $booking = (new CreateBooking())->handle(
        resourceId: $resource->id,
        externalType: $resource->external_type,
        externalId: $resource->external_id,
        startsAt: Carbon::parse('2026-07-14T09:00:00Z'),
        endsAt: Carbon::parse('2026-07-14T10:00:00Z'),
        metadata: [],
    );

    $response = $this->withHeaders($headers)
        ->deleteJson("/api/resources/{$resource->id}/bookings/{$booking->id}");

    $response->assertStatus(204);
    $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
});

it('returns 403 when deleting a booking through the wrong resource', function () {
    $resourceA = Resource::factory()->create();
    $resourceB = Resource::factory()->create();
    $headers = apiKeyHeader();

    $booking = (new CreateBooking())->handle(
        resourceId: $resourceA->id,
        externalType: $resourceA->external_type,
        externalId: $resourceA->external_id,
        startsAt: Carbon::parse('2026-07-14T09:00:00Z'),
        endsAt: Carbon::parse('2026-07-14T10:00:00Z'),
        metadata: [],
    );

    $response = $this->withHeaders($headers)
        ->deleteJson("/api/resources/{$resourceB->id}/bookings/{$booking->id}");

    $response->assertStatus(403);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
});
