<?php

use App\Models\Resource;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $this->path = "/api/resources/{$this->resource->id}/bookings";
    $this->times = ['starts_at' => '2026-08-03T09:00:00Z', 'ends_at' => '2026-08-03T10:00:00Z'];
    $this->created = $this->postJson($this->path, $this->times + ['metadata' => ['appointment_id' => 7]], ['Idempotency-Key' => 'original'])->assertCreated();
    $this->uri = $this->path.'/'.$this->created->json('id');
});

it('returns the same full booking and quoted version on POST replay GET and list', function () {
    $this->created->assertJsonPath('lock_version', 1)->assertHeader('ETag', '"1"');
    $this->getJson($this->uri)->assertOk()->assertOpenApi('/resources/{resource}/bookings/{booking}', 'get')->assertExactJson($this->created->json())->assertHeader('ETag', '"1"');
    $this->postJson($this->path, $this->times + ['metadata' => ['appointment_id' => 7]], ['Idempotency-Key' => 'original'])
        ->assertOk()->assertHeader('ETag', '"1"')->assertExactJson($this->created->json());
    expect($this->getJson($this->path)->json(0))->toEqual($this->created->json());
});

it('returns not_found for missing booking resource and wrong parent', function (string $method, string $target) {
    $uri = match ($target) {
        'booking' => $this->path.'/999999',
        'resource' => '/api/resources/999999/bookings/'.$this->created->json('id'),
        'parent' => '/api/resources/'.Resource::factory()->create()->id.'/bookings/'.$this->created->json('id'),
    };
    $this->json($method, $uri, $this->times, ['If-Match' => '"1"'])->assertNotFound()->assertJsonPath('code', 'not_found')->assertOpenApi('/resources/{resource}/bookings/{booking}', $method);
})->with(['get', 'patch'])->with(['booking', 'resource', 'parent']);

it('atomically moves across its old range and preserves all non-time fields', function () {
    $response = $this->patchJson($this->uri, ['starts_at' => '2026-08-03T09:30:00Z', 'ends_at' => '2026-08-03T10:30:00Z', 'metadata' => ['appointment_id' => 99], 'resource_id' => 999], ['If-Match' => '"1"', 'Idempotency-Key' => 'invalid key!'])
        ->assertOk()->assertJsonPath('lock_version', 2)->assertHeader('ETag', '"2"')
        ->assertJsonPath('starts_at', '2026-08-03T09:30:00+00:00')->assertOpenApi('/resources/{resource}/bookings/{booking}', 'patch');
    foreach (['id', 'resource_id', 'external_type', 'external_id', 'metadata', 'idempotency_key', 'created_at'] as $field) {
        expect($response->json($field))->toBe($this->created->json($field));
    }
    expect($this->getJson($this->uri)->json())->toBe($response->json());
    $this->postJson($this->path, $this->times + ['metadata' => ['appointment_id' => 7]], ['Idempotency-Key' => 'original'])
        ->assertUnprocessable()->assertJsonPath('code', 'idempotency_mismatch');
});

it('retains the entire row after conflict and allows adjacency', function () {
    $this->postJson($this->path, ['starts_at' => '2026-08-03T11:00:00Z', 'ends_at' => '2026-08-03T12:00:00Z'])->assertCreated();
    $before = (array) DB::table('bookings')->find($this->created->json('id'));
    $this->patchJson($this->uri, ['starts_at' => '2026-08-03T10:30:00Z', 'ends_at' => '2026-08-03T11:30:00Z'], ['If-Match' => '"1"'])
        ->assertConflict()->assertJsonPath('code', 'booking_conflict')->assertOpenApi('/resources/{resource}/bookings/{booking}', 'patch');
    expect((array) DB::table('bookings')->find($this->created->json('id')))->toBe($before);
    $this->patchJson($this->uri, ['starts_at' => '2026-08-03T10:00:00Z', 'ends_at' => '2026-08-03T11:00:00Z'], ['If-Match' => '"1"'])
        ->assertOk()->assertHeader('ETag', '"2"');
});

it('requires a current strong If-Match and leaves failed preconditions unchanged', function (?string $header, int $status, string $code) {
    $headers = $header === null ? [] : ['If-Match' => $header];
    $before = (array) DB::table('bookings')->find($this->created->json('id'));
    $this->patchJson($this->uri, $this->times, $headers)->assertStatus($status)->assertJsonPath('code', $code)->assertOpenApi('/resources/{resource}/bookings/{booking}', 'patch');
    expect((array) DB::table('bookings')->find($this->created->json('id')))->toBe($before);
})->with([[null, 428, 'precondition_required'], ['"0"', 412, 'precondition_failed'], ['W/"1"', 412, 'precondition_failed'], ['1', 412, 'precondition_failed'], ['*', 412, 'precondition_failed']]);

it('increments the version on no-op and gives stale version precedence over overlap', function () {
    $this->patchJson($this->uri, $this->times, ['If-Match' => '"1"'])->assertOk()->assertJsonPath('lock_version', 2)->assertHeader('ETag', '"2"');
    $other = ['starts_at' => '2026-08-03T11:00:00Z', 'ends_at' => '2026-08-03T12:00:00Z'];
    $this->postJson($this->path, $other)->assertCreated();
    $this->patchJson($this->uri, $other, ['If-Match' => '"1"'])->assertStatus(412)->assertJsonPath('code', 'precondition_failed');
    $this->getJson($this->uri)->assertJsonPath('lock_version', 2)->assertJsonPath('starts_at', $this->created->json('starts_at'));
});

it('validates reschedule times exactly as POST', function (array $body, string $field) {
    $this->patchJson($this->uri, $body, ['If-Match' => '"1"'])->assertUnprocessable()->assertJsonValidationErrors($field)->assertOpenApi('/resources/{resource}/bookings/{booking}', 'patch');
})->with([
    [[], 'starts_at'], [['starts_at' => '2026-08-03T09:00:00Z'], 'ends_at'],
    [['starts_at' => '2026-08-03T09:00:00', 'ends_at' => '2026-08-03T10:00:00Z'], 'starts_at'],
    [['starts_at' => '2026-08-03T09:00:00Z', 'ends_at' => '2026-08-03T10:00:00'], 'ends_at'],
    [['starts_at' => '2026-08-03T10:00:00Z', 'ends_at' => '2026-08-03T09:00:00Z'], 'ends_at'],
    [['starts_at' => '2026-08-03T09:00:00Z', 'ends_at' => '2026-08-03T09:00:00Z'], 'ends_at'],
]);

it('uses the database clock for updated_at even when the application clock differs', function () {
    $before = DB::selectOne('SELECT clock_timestamp()::timestamp(0) AS time')->time;
    $this->travelTo(Carbon\Carbon::parse('2001-01-01T00:00:00Z'));
    try {
        $this->patchJson($this->uri, $this->times, ['If-Match' => '"1"'])->assertOk();
        $stored = DB::table('bookings')->find($this->created->json('id'));
        $after = DB::selectOne('SELECT clock_timestamp()::timestamp(0) AS time')->time;
        expect(Carbon\Carbon::parse($stored->updated_at)->betweenIncluded(Carbon\Carbon::parse($before), Carbon\Carbon::parse($after)))->toBeTrue();
    } finally {
        $this->travelBack();
    }
});
