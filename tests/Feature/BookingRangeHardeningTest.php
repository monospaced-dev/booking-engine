<?php

use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('limits POST and PATCH durations to 366 elapsed days', function (string $method, int $seconds, int $status) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $url = "/api/resources/{$resource->id}/bookings";
    $start = Carbon::parse('2026-01-01T00:00:00Z');
    $payload = ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addSeconds($seconds)->toIso8601String()];
    if ($method === 'patch') {
        $created = $this->postJson($url, ['starts_at' => $payload['starts_at'], 'ends_at' => '2026-01-01T01:00:00Z'])->assertCreated();
        $url .= '/'.$created->json('id');
    }
    $response = $this->{$method.'Json'}($url, $payload, ['If-Match' => '"1"'])->assertStatus($status);
    if ($status === 422) {
        $response->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('ends_at');
        if ($method === 'patch') {
            $this->getJson($url)->assertExactJson($created->json());
        } else {
            $this->assertDatabaseCount('bookings', 0);
        }
    }
})->with([['post', 366 * 86400, 201], ['post', 366 * 86400 + 1, 422], ['patch', 366 * 86400, 200], ['patch', 366 * 86400 + 1, 422]]);

it('rejects empty or infinite ranges in the database', function (string $range) {
    $resource = Resource::factory()->create();
    try {
        DB::transaction(fn () => DB::insert('INSERT INTO bookings (resource_id, external_type, external_id, during) VALUES (?, ?, ?, ?::tstzrange)',
            [$resource->id, $resource->external_type, $resource->external_id, $range]));
        $this->fail('Invalid range was accepted.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23514');
        expect($exception->getMessage())->toContain('bookings_during_finite_nonempty');
    }
})->with(['empty', '(,2026-01-01)', '[2026-01-01,)', '[-infinity,2026-01-01)', '[2026-01-01,infinity)']);

it('validates legacy rows and leaves the old finite range writer compatible', function () {
    $migration = require database_path('migrations/2026_10_11_180000_constrain_booking_ranges.php');
    $resource = Resource::factory()->create();
    $migration->down();
    DB::insert("INSERT INTO bookings (resource_id, external_type, external_id, during) VALUES (?, ?, ?, 'empty'::tstzrange)",
        [$resource->id, $resource->external_type, $resource->external_id]);
    try {
        DB::transaction(fn () => $migration->up());
        $this->fail('Migration must reject invalid existing rows.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23514');
    }
    DB::table('bookings')->where('resource_id', $resource->id)->delete();
    $migration->up();
    // The old writer omits no newly required columns, and its long finite
    // ranges remain legal: the duration sanity limit belongs to new requests.
    DB::insert("INSERT INTO bookings (resource_id, external_type, external_id, during, metadata) VALUES (?, ?, ?, '[2026-01-01,2028-01-01)'::tstzrange, '[]'::jsonb)",
        [$resource->id, $resource->external_type, $resource->external_id]);
    expect(DB::table('bookings')->where('resource_id', $resource->id)->count())->toBe(1);
});
