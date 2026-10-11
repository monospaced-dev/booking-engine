<?php

use App\Models\Blackout;
use App\Models\Resource;

it('includes boundary blackouts with UTC and local-offset windows', function (
    string $during, array $utcWindow, array $localWindow, array $expected
) {
    $resource = Resource::factory()->create();
    Blackout::factory()->create(['resource_id' => $resource->id, 'during' => $during]);
    $headers = apiKeyHeader();

    // Both representations of the same window must include the instant range.
    foreach ([$utcWindow, $localWindow] as $window) {
        $this->withHeaders($headers)
            ->getJson("/api/resources/{$resource->id}/busy?".http_build_query($window))
            ->assertOk()->assertExactJson([$expected]);
    }
})->with([
    'Chicago previous local date' => [
        '[2026-07-20T20:00:00-05:00,2026-07-20T21:00:00-05:00)',
        ['from' => '2026-07-21T00:00:00Z', 'to' => '2026-07-21T12:00:00Z'],
        ['from' => '2026-07-20T19:00:00-05:00', 'to' => '2026-07-21T07:00:00-05:00'],
        ['starts_at' => '2026-07-21T01:00:00+00:00', 'ends_at' => '2026-07-21T02:00:00+00:00'],
    ],
    'Tokyo next local date' => [
        '[2026-07-22T06:00:00+09:00,2026-07-22T07:00:00+09:00)',
        ['from' => '2026-07-21T12:00:00Z', 'to' => '2026-07-21T23:00:00Z'],
        ['from' => '2026-07-21T21:00:00+09:00', 'to' => '2026-07-22T08:00:00+09:00'],
        ['starts_at' => '2026-07-21T21:00:00+00:00', 'ends_at' => '2026-07-21T22:00:00+00:00'],
    ],
]);
