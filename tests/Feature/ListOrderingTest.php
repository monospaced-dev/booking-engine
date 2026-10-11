<?php

use App\Actions\CreateBooking;
use App\Models\Blackout;
use App\Models\Resource;
use Carbon\Carbon;

it('orders booking and blackout lists by range start then id with and without a window', function (bool $filtered, string $endpoint) {
    $resource = Resource::factory()->create();
    $create = new CreateBooking;
    $late = $create->handle($resource->id, $resource->external_type, $resource->external_id, Carbon::parse('2026-07-21T12:00:00Z'), Carbon::parse('2026-07-21T13:00:00Z'));
    $early = $create->handle($resource->id, $resource->external_type, $resource->external_id, Carbon::parse('2026-07-21T09:00:00Z'), Carbon::parse('2026-07-21T10:00:00Z'));
    // IDs intentionally disagree with insertion order to exercise the tie-break.
    $lateBlackout = Blackout::factory()->create(['resource_id' => $resource->id, 'id' => 900, 'during' => '[2026-07-21T12:00:00Z,2026-07-21T13:00:00Z)']);
    $higher = Blackout::factory()->create(['resource_id' => $resource->id, 'id' => 800, 'during' => '[2026-07-21T09:00:00Z,2026-07-21T11:00:00Z)']);
    $lower = Blackout::factory()->create(['resource_id' => $resource->id, 'id' => 700, 'during' => '[2026-07-21T09:00:00Z,2026-07-21T10:00:00Z)']);
    $query = $filtered ? '?from=2026-07-21T00:00:00Z&to=2026-07-22T00:00:00Z' : '';
    $this->withHeaders(apiKeyHeader());
    $expected = $endpoint === 'bookings' ? [$early->id, $late->id] : [$lower->id, $higher->id, $lateBlackout->id];
    expect($this->getJson("/api/resources/{$resource->id}/{$endpoint}".$query)->assertOk()->json('*.id'))->toBe($expected);
})->with([[false, 'bookings'], [true, 'bookings'], [false, 'blackouts'], [true, 'blackouts']]);
