<?php

use App\Models\BlackoutDate;
use App\Models\Resource;

it('includes boundary blackouts with UTC and local-offset windows', function (
    string $timezone, string $date, string $startTime, string $endTime,
    array $utcWindow, array $localWindow, array $expected
) {
    $resource = Resource::factory()->create(['timezone' => $timezone]);
    BlackoutDate::factory()->create([
        'resource_id' => $resource->id,
        'date' => $date,
        'start_time' => $startTime,
        'end_time' => $endTime,
    ]);
    $headers = apiKeyHeader();

    // Both representations of the same window must include the local-date row.
    foreach ([$utcWindow, $localWindow] as $window) {
        $this->withHeaders($headers)
            ->getJson("/api/resources/{$resource->id}/busy?".http_build_query($window))
            ->assertOk()->assertExactJson([$expected]);
    }
})->with([
    'Chicago previous local date' => [
        'America/Chicago', '2026-07-20', '20:00', '21:00',
        ['from' => '2026-07-21T00:00:00Z', 'to' => '2026-07-21T12:00:00Z'],
        ['from' => '2026-07-20T19:00:00-05:00', 'to' => '2026-07-21T07:00:00-05:00'],
        ['starts_at' => '2026-07-21T01:00:00+00:00', 'ends_at' => '2026-07-21T02:00:00+00:00'],
    ],
    'Tokyo next local date' => [
        'Asia/Tokyo', '2026-07-22', '06:00', '07:00',
        ['from' => '2026-07-21T12:00:00Z', 'to' => '2026-07-21T23:00:00Z'],
        ['from' => '2026-07-21T21:00:00+09:00', 'to' => '2026-07-22T08:00:00+09:00'],
        ['starts_at' => '2026-07-21T21:00:00+00:00', 'ends_at' => '2026-07-21T22:00:00+00:00'],
    ],
]);
