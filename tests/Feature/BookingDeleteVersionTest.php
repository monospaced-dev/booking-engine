<?php

use App\Actions\CreateBooking;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

it('conditionally deletes using one parent-scoped version comparison statement', function () {
    $resource = Resource::factory()->create();
    $booking = (new CreateBooking)->handle($resource->id, $resource->external_type, $resource->external_id,
        Carbon::parse('2026-07-21T09:00:00Z'), Carbon::parse('2026-07-21T10:00:00Z'));
    $queries = [];
    DB::listen(function ($event) use (&$queries) {
        if (str_starts_with(strtolower($event->sql), 'delete from "bookings"')) {
            $queries[] = $event->sql;
        }
    });
    $this->withHeaders(apiKeyHeader())->deleteJson("/api/resources/{$resource->id}/bookings/{$booking->id}", [], ['If-Match' => '"1"'])
        ->assertNoContent()->assertOpenApi('/resources/{resource}/bookings/{booking}', 'delete');
    expect($queries)->toHaveCount(1);
    expect($queries[0])->toContain('"resource_id"', '"lock_version"');
    $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
});

it('preserves a booking for stale or malformed optional If-Match', function (string $tag) {
    $resource = Resource::factory()->create();
    $booking = (new CreateBooking)->handle($resource->id, $resource->external_type, $resource->external_id,
        Carbon::parse('2026-07-21T09:00:00Z'), Carbon::parse('2026-07-21T10:00:00Z'));
    $this->withHeaders(apiKeyHeader())->deleteJson("/api/resources/{$resource->id}/bookings/{$booking->id}", [], ['If-Match' => $tag])
        ->assertStatus(412)->assertJsonPath('code', 'precondition_failed')
        ->assertOpenApi('/resources/{resource}/bookings/{booking}', 'delete');
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'lock_version' => 1]);
})->with(['"2"', '1', 'W/"1"', '*', '"1", "2"', '"0"', '"2147483648"', '', '"1"'."\n"]);

it('preserves unconditional deletion and hides ownership mismatches', function () {
    $resource = Resource::factory()->create();
    $other = Resource::factory()->create();
    $booking = (new CreateBooking)->handle($resource->id, $resource->external_type, $resource->external_id,
        Carbon::parse('2026-07-21T09:00:00Z'), Carbon::parse('2026-07-21T10:00:00Z'));
    $this->withHeaders(apiKeyHeader())->deleteJson("/api/resources/{$other->id}/bookings/{$booking->id}", [], ['If-Match' => '"2"'])->assertNotFound();
    $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    $this->deleteJson("/api/resources/{$resource->id}/bookings/{$booking->id}")->assertNoContent();
});
