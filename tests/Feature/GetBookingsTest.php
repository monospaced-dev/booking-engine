<?php

use App\Actions\CreateBooking;
use App\Models\Resource;
use Carbon\Carbon;

beforeEach(function () {
    $this->resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $this->path = "/api/resources/{$this->resource->id}/bookings";
});

it('lists only this resource bookings without exposing the database range', function () {
    $other = Resource::factory()->create();
    foreach ([$this->resource, $other] as $resource) {
        (new CreateBooking)->handle($resource->id, $resource->external_type, $resource->external_id,
            Carbon::parse('2026-07-21T09:00:00Z'), Carbon::parse('2026-07-21T10:00:00Z'));
    }
    $response = $this->getJson($this->path)->assertOk()->assertOpenApi('/resources/{resource}/bookings', 'get');
    expect($response->json())->toHaveCount(1);
    expect($response->json('0.resource_id'))->toBe($this->resource->id);
    expect($response->json('0'))->not->toHaveKey('during');
});

it('executes the Postgres overlap predicate with half-open boundaries and offset bounds', function () {
    foreach ([['08:00', '09:00'], ['09:00', '10:00'], ['10:00', '11:00']] as [$start, $end]) {
        (new CreateBooking)->handle($this->resource->id, $this->resource->external_type, $this->resource->external_id,
            Carbon::parse("2026-07-21T{$start}:00Z"), Carbon::parse("2026-07-21T{$end}:00Z"));
    }
    $query = http_build_query(['from' => '2026-07-21T04:00:00-05:00', 'to' => '2026-07-21T05:00:00-05:00']);
    $response = $this->getJson($this->path.'?'.$query)->assertOk()->assertOpenApi('/resources/{resource}/bookings', 'get');
    expect($response->json())->toHaveCount(1);
    expect($response->json('0.starts_at'))->toBe('2026-07-21T09:00:00+00:00');
    // Overlap returns the full range, not the clipped query window.
    $response = $this->getJson($this->path.'?'.http_build_query([
        'from' => '2026-07-21T09:15:00Z', 'to' => '2026-07-21T09:45:00Z',
    ]))->assertOk()->assertOpenApi('/resources/{resource}/bookings', 'get');
    expect($response->json('0.ends_at'))->toBe('2026-07-21T10:00:00+00:00');
});

it('returns an empty booking array for an empty resource or disjoint window', function () {
    $this->getJson($this->path)->assertOk()->assertExactJson([])->assertOpenApi('/resources/{resource}/bookings', 'get');
    (new CreateBooking)->handle($this->resource->id, $this->resource->external_type, $this->resource->external_id,
        Carbon::parse('2026-07-21T09:00:00Z'), Carbon::parse('2026-07-21T10:00:00Z'));
    $this->getJson($this->path.'?'.http_build_query(['from' => '2026-07-22T09:00:00Z', 'to' => '2026-07-22T10:00:00Z']))
        ->assertOk()->assertExactJson([])->assertOpenApi('/resources/{resource}/bookings', 'get');
});

it('rejects incomplete reversed or naive booking windows', function (array $query, string $field) {
    $this->getJson($this->path.'?'.http_build_query($query))->assertUnprocessable()
        ->assertJsonValidationErrors($field)->assertOpenApi('/resources/{resource}/bookings', 'get');
})->with([
    [['from' => '2026-07-21T09:00:00Z'], 'to'],
    [['to' => '2026-07-21T10:00:00Z'], 'from'],
    [['from' => '2026-07-21T10:00:00Z', 'to' => '2026-07-21T09:00:00Z'], 'to'],
    [['from' => '2026-07-21T09:00:00', 'to' => '2026-07-21T10:00:00Z'], 'from'],
]);
