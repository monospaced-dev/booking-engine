<?php

use App\Actions\CreateBooking;
use App\Actions\GetBusyRanges;
use App\Exceptions\BookingConflictException;
use App\Models\Blackout;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create();
    $this->action = new GetBusyRanges();
});

/**
 * @throws BookingConflictException
 */
function createBooking(Resource $resource, string $startsAt, string $endsAt): void
{
    new CreateBooking()->handle(
        resourceId: $resource->id,
        externalType: $resource->external_type,
        externalId: $resource->external_id,
        startsAt: Carbon::parse($startsAt),
        endsAt: Carbon::parse($endsAt),
    );
}

it('returns no busy windows when there are no bookings or blackouts', function () {
    $result = $this->action->handle(
        $this->resource,
        Carbon::parse('2026-07-20T00:00:00Z'),
        Carbon::parse('2026-07-22T00:00:00Z'),
    );

    expect($result)->toBeEmpty();
});

it('includes a booking inside the queried range as a busy window in UTC', function () {
    createBooking($this->resource, '2026-07-20T14:00:00Z', '2026-07-20T15:00:00Z');

    $result = $this->action->handle(
        $this->resource,
        Carbon::parse('2026-07-20T00:00:00Z'),
        Carbon::parse('2026-07-21T00:00:00Z'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->toIso8601String())->toBe('2026-07-20T14:00:00+00:00')
        ->and($result[0]['end']->toIso8601String())->toBe('2026-07-20T15:00:00+00:00');
});

it('excludes a booking that ends entirely before the queried range', function () {
    createBooking($this->resource, '2026-07-18T14:00:00Z', '2026-07-18T15:00:00Z');

    $result = $this->action->handle(
        $this->resource,
        Carbon::parse('2026-07-20T00:00:00Z'),
        Carbon::parse('2026-07-22T00:00:00Z'),
    );

    expect($result)->toBeEmpty();
});

it('excludes a booking that starts entirely after the queried range', function () {
    createBooking($this->resource, '2026-07-25T14:00:00Z', '2026-07-25T15:00:00Z');

    $result = $this->action->handle(
        $this->resource,
        Carbon::parse('2026-07-20T00:00:00Z'),
        Carbon::parse('2026-07-22T00:00:00Z'),
    );

    expect($result)->toBeEmpty();
});

it('returns the full-day Chicago blackout instants', function () {
    Blackout::factory()->create([
        'resource_id' => $this->resource->id,
        'during' => '[2026-07-21T05:00:00Z,2026-07-22T05:00:00Z)',
    ]);

    $result = $this->action->handle(
        $this->resource,
        Carbon::parse('2026-07-20T00:00:00Z'),
        Carbon::parse('2026-07-23T00:00:00Z'),
    );

    // America/Chicago is UTC-5 (CDT) in July
    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->toIso8601String())->toBe('2026-07-21T05:00:00+00:00')
        ->and($result[0]['end']->toIso8601String())->toBe('2026-07-22T05:00:00+00:00');
});

it('returns the partial-day Chicago blackout instants', function () {
    Blackout::factory()->create([
        'resource_id' => $this->resource->id,
        'during' => '[2026-07-21T17:00:00Z,2026-07-21T18:00:00Z)',
    ]);

    $result = $this->action->handle(
        $this->resource,
        Carbon::parse('2026-07-20T00:00:00Z'),
        Carbon::parse('2026-07-23T00:00:00Z'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->toIso8601String())->toBe('2026-07-21T17:00:00+00:00')
        ->and($result[0]['end']->toIso8601String())->toBe('2026-07-21T18:00:00+00:00');
});

it('merges an overlapping booking and blackout into a single busy window', function () {
    createBooking($this->resource, '2026-07-21T16:00:00Z', '2026-07-21T17:30:00Z');

    // 12:00-13:00 Chicago = 17:00-18:00 UTC, overlapping the tail of the booking above
    Blackout::factory()->create([
        'resource_id' => $this->resource->id,
        'during' => '[2026-07-21T17:00:00Z,2026-07-21T18:00:00Z)',
    ]);

    $result = $this->action->handle(
        $this->resource,
        Carbon::parse('2026-07-20T00:00:00Z'),
        Carbon::parse('2026-07-23T00:00:00Z'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->toIso8601String())->toBe('2026-07-21T16:00:00+00:00')
        ->and($result[0]['end']->toIso8601String())->toBe('2026-07-21T18:00:00+00:00');
});

it('excludes blackout ranges outside the query window', function () {
    Blackout::factory()->create([
        'resource_id' => $this->resource->id,
        'during' => '[2026-07-22T01:00:00Z,2026-07-22T02:00:00Z)',
    ]);

    // 'to' is midnight UTC on 2026-07-21 — the blackout's actual UTC window
    // (2026-07-22T01:00-02:00, since 20:00 Chicago = 01:00 UTC next day) falls
    // entirely after it, even though the date column itself is in range.
    $result = $this->action->handle(
        $this->resource,
        Carbon::parse('2026-07-20T00:00:00Z'),
        Carbon::parse('2026-07-21T00:00:00Z'),
    );

    expect($result)->toBeEmpty();
});

it('returns busy windows from the HTTP endpoint as ISO8601 strings', function () {
    createBooking($this->resource, '2026-07-20T14:00:00Z', '2026-07-20T15:00:00Z');

    $response = $this->withHeaders(apiKeyHeader())->getJson(
        "/api/resources/{$this->resource->id}/busy?from=2026-07-20T00:00:00Z&to=2026-07-21T00:00:00Z"
    );

    $response->assertStatus(200)
        ->assertJsonCount(1)
        ->assertJson([
            [
                'starts_at' => '2026-07-20T14:00:00+00:00',
                'ends_at' => '2026-07-20T15:00:00+00:00',
            ],
        ]);
});

it('returns 422 from the HTTP endpoint when to is not after from', function () {
    $response = $this->withHeaders(apiKeyHeader())->getJson(
        "/api/resources/{$this->resource->id}/busy?from=2026-07-21T00:00:00Z&to=2026-07-20T00:00:00Z"
    );

    $response->assertStatus(422);
});

it('returns 422 from the HTTP endpoint when from or to is missing', function () {
    $this->withHeaders(apiKeyHeader())
        ->getJson("/api/resources/{$this->resource->id}/busy?to=2026-07-21T00:00:00Z")
        ->assertStatus(422);

    $this->withHeaders(apiKeyHeader())
        ->getJson("/api/resources/{$this->resource->id}/busy?from=2026-07-20T00:00:00Z")
        ->assertStatus(422);
});

it('merges overlapping and adjacent blackouts clips bounds and isolates resources', function () {
    foreach (['[2026-07-21T10:00:00Z,2026-07-21T12:00:00Z)', '[2026-07-21T11:00:00Z,2026-07-21T13:00:00Z)', '[2026-07-21T13:00:00Z,2026-07-21T15:00:00Z)'] as $during) {
        Blackout::factory()->create(['resource_id' => $this->resource->id, 'during' => $during]);
    }
    Blackout::factory()->create(['during' => '[2026-07-21T16:00:00Z,2026-07-21T17:00:00Z)']);
    $this->withHeaders(apiKeyHeader())->getJson("/api/resources/{$this->resource->id}/busy?from=2026-07-21T11:00:00Z&to=2026-07-21T14:00:00Z")
        ->assertExactJson([['starts_at' => '2026-07-21T11:00:00+00:00', 'ends_at' => '2026-07-21T14:00:00+00:00']]);
    $this->getJson("/api/resources/{$this->resource->id}/busy?from=2026-07-21T15:00:00Z&to=2026-07-21T16:00:00Z")->assertExactJson([]);
});
