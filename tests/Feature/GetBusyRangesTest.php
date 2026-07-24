<?php

use App\Actions\CreateBooking;
use App\Actions\GetBusyRanges;
use App\Exceptions\BookingConflictException;
use App\Models\BlackoutDate;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create(['timezone' => 'America/Chicago']);
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

it('returns no busy windows when there are no bookings or blackout dates', function () {
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

it('converts a full-day blackout into the correct UTC window for a non-UTC resource timezone', function () {
    BlackoutDate::factory()->create([
        'resource_id' => $this->resource->id,
        'date' => '2026-07-21',
        'start_time' => null,
        'end_time' => null,
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

it('converts a partial-day blackout into the correct UTC window for a non-UTC resource timezone', function () {
    BlackoutDate::factory()->create([
        'resource_id' => $this->resource->id,
        'date' => '2026-07-21',
        'start_time' => '12:00',
        'end_time' => '13:00',
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

it('merges an overlapping booking and blackout date into a single busy window', function () {
    createBooking($this->resource, '2026-07-21T16:00:00Z', '2026-07-21T17:30:00Z');

    // 12:00-13:00 Chicago = 17:00-18:00 UTC, overlapping the tail of the booking above
    BlackoutDate::factory()->create([
        'resource_id' => $this->resource->id,
        'date' => '2026-07-21',
        'start_time' => '12:00',
        'end_time' => '13:00',
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

it('clips out a blackout date whose row matches the coarse date filter but whose actual time falls outside the queried window', function () {
    // The DB query filters blackout dates by date column alone (whereBetween on
    // $from->toDateString()/$to->toDateString()), which is coarser than the real
    // instant range. A blackout dated on the boundary day but timed well outside
    // the query window should still be excluded from the final result — that
    // protection comes entirely from Interval::mergeExclusions's clipping, not
    // from the DB query itself.
    BlackoutDate::factory()->create([
        'resource_id' => $this->resource->id,
        'date' => '2026-07-21',
        'start_time' => '20:00',
        'end_time' => '21:00',
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
