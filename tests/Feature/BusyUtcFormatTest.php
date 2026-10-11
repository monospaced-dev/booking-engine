<?php

use App\Actions\CreateBooking;
use App\Models\Resource;
use Carbon\Carbon;

it('formats every busy timestamp as UTC when local-offset windows clip bookings', function (array $window) {
    $resource = Resource::factory()->create();
    new CreateBooking()->handle(
        resourceId: $resource->id,
        externalType: $resource->external_type,
        externalId: $resource->external_id,
        startsAt: Carbon::parse('2026-07-21T15:00:00Z'),
        endsAt: Carbon::parse('2026-07-21T17:00:00Z'),
    );

    $response = $this->withHeaders(apiKeyHeader())
        ->getJson("/api/resources/{$resource->id}/busy?".http_build_query($window));

    $response->assertOk()->assertExactJson([
        ['starts_at' => '2026-07-21T15:30:00+00:00', 'ends_at' => '2026-07-21T16:30:00+00:00'],
    ]);
    foreach ($response->json() as $range) {
        foreach ($range as $timestamp) {
            expect($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/');
        }
    }
})->with([
    'Chicago' => [['from' => '2026-07-21T10:30:00-05:00', 'to' => '2026-07-21T11:30:00-05:00']],
    'Tokyo' => [['from' => '2026-07-22T00:30:00+09:00', 'to' => '2026-07-22T01:30:00+09:00']],
]);
