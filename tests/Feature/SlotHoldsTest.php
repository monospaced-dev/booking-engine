<?php

use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $this->path = "/api/resources/{$this->resource->id}/bookings";
    $this->payload = ['starts_at' => '2026-11-01T09:00:00Z', 'ends_at' => '2026-11-01T10:00:00Z'];
});

it('creates a database timed hold and preserves default confirmed bookings', function () {
    $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 60])->assertCreated()->assertJsonPath('status', 'held')->assertOpenApi('/resources/{resource}/bookings', 'post');
    $row = DB::selectOne('SELECT expires_at = now() + interval \'60 seconds\' AS correct FROM bookings WHERE id = ?', [$hold->json('id')]);
    expect($row->correct)->toBeTrue();
    $this->deleteJson($this->path.'/'.$hold->json('id'))->assertNoContent();
    $this->postJson($this->path, $this->payload)->assertCreated()->assertJsonPath('status', 'confirmed')->assertJsonPath('expires_at', null);
});

it('rejects invalid hold durations', function ($seconds) {
    $this->postJson($this->path, $this->payload + ['hold_seconds' => $seconds])->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
})->with([59, 901, null, 60.5, 'abc']);

it('blocks overlaps until expiry then removes the expired row', function () {
    $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 900])->assertCreated();
    $this->postJson($this->path, $this->payload)->assertConflict();
    DB::update('UPDATE bookings SET expires_at = now() WHERE id = ?', [$hold->json('id')]);
    $this->postJson($this->path, $this->payload)->assertCreated();
    $this->assertDatabaseMissing('bookings', ['id' => $hold->json('id')]);
    $this->postJson($this->path.'/'.$hold->json('id').'/confirm')->assertGone()->assertJsonPath('code', 'hold_expired')->assertOpenApi('/resources/{resource}/bookings/{booking}/confirm', 'post');
    $other = Resource::factory()->create();
    $this->postJson("/api/resources/{$other->id}/bookings/{$hold->json('id')}/confirm")->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('filters expired holds from both busy endpoints and booking lists', function () {
    $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 60])->assertCreated();
    DB::update('UPDATE bookings SET expires_at = now() WHERE id = ?', [$hold->json('id')]);
    $window = ['from' => $this->payload['starts_at'], 'to' => $this->payload['ends_at']];
    $this->getJson("/api/resources/{$this->resource->id}/busy?".http_build_query($window))->assertExactJson([]);
    $this->postJson('/api/busy', $window + ['resource_ids' => [$this->resource->id]])->assertJsonPath('busy.'.$this->resource->id, []);
    $this->getJson($this->path)->assertExactJson([]);
    $this->getJson($this->path.'?'.http_build_query($window))->assertExactJson([]);
    $this->assertDatabaseHas('bookings', ['id' => $hold->json('id')]);
});

it('confirms atomically and repeats without changing the version', function () {
    $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 60])->assertCreated();
    $uri = $this->path.'/'.$hold->json('id').'/confirm';
    $confirmed = $this->postJson($uri, [], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('expires_at', null)->assertJsonPath('lock_version', 2)->assertHeader('ETag', '"2"')->assertOpenApi('/resources/{resource}/bookings/{booking}/confirm', 'post');
    expect($this->postJson($uri)->assertOk()->json())->toEqual($confirmed->json());
    $this->postJson($uri, [], ['If-Match' => '"1"'])->assertStatus(412)->assertOpenApi('/resources/{resource}/bookings/{booking}/confirm', 'post');
});

it('returns expiry and missing errors and respects optional If-Match', function () {
    $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 60])->assertCreated();
    $uri = $this->path.'/'.$hold->json('id').'/confirm';
    foreach (['W/"1"', '*', '1', '"2"'] as $tag) {
        $this->postJson($uri, [], ['If-Match' => $tag])->assertStatus(412)->assertOpenApi('/resources/{resource}/bookings/{booking}/confirm', 'post');
    }
    DB::update('UPDATE bookings SET expires_at = now() WHERE id = ?', [$hold->json('id')]);
    $this->postJson($uri)->assertGone()->assertJsonPath('code', 'hold_expired')->assertOpenApi('/resources/{resource}/bookings/{booking}/confirm', 'post');
    $this->postJson($this->path.'/999999/confirm')->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('includes hold duration in replay even after confirmation', function () {
    $headers = ['Idempotency-Key' => 'hold-replay'];
    $payload = $this->payload + ['hold_seconds' => 60];
    $hold = $this->postJson($this->path, $payload, $headers)->assertCreated();
    expect($this->postJson($this->path, $payload, $headers)->assertOk()->json())->toEqual($hold->json());
    $this->postJson($this->path, $this->payload + ['hold_seconds' => 61], $headers)->assertUnprocessable()->assertJsonPath('code', 'idempotency_mismatch');
    $this->postJson($this->path.'/'.$hold->json('id').'/confirm')->assertOk();
    $this->postJson($this->path, $payload, $headers)->assertOk()->assertJsonPath('status', 'confirmed');
    $this->postJson($this->path, $this->payload, $headers)->assertUnprocessable();
});

it('patches holds without extending them and cleans expired overlaps', function () {
    $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 60])->assertCreated();
    $target = ['starts_at' => '2026-11-01T11:00:00Z', 'ends_at' => '2026-11-01T12:00:00Z'];
    $expired = $this->postJson($this->path, $target + ['hold_seconds' => 60])->assertCreated();
    DB::update('UPDATE bookings SET expires_at = now() WHERE id = ?', [$expired->json('id')]);
    $this->patchJson($this->path.'/'.$hold->json('id'), $target, ['If-Match' => '"1"'])->assertOk()->assertJsonPath('status', 'held')->assertJsonPath('expires_at', $hold->json('expires_at'));
    $this->assertDatabaseMissing('bookings', ['id' => $expired->json('id')]);
});

it('uses the database clock even when the application clock is frozen', function () {
    $this->travelTo(Carbon::parse('1999-01-01T00:00:00Z'));
    try {
        $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 900])->assertCreated();
        expect(DB::selectOne('SELECT expires_at > now() AND expires_at <= now() + interval \'900 seconds\' AS live FROM bookings WHERE id = ?', [$hold->json('id')])->live)->toBeTrue();
        $window = ['from' => $this->payload['starts_at'], 'to' => $this->payload['ends_at']];
        $this->getJson("/api/resources/{$this->resource->id}/busy?".http_build_query($window))->assertJsonCount(1);
        $this->postJson('/api/busy', $window + ['resource_ids' => [$this->resource->id]])->assertJsonCount(1, 'busy.'.$this->resource->id);
        $this->postJson($this->path.'/'.$hold->json('id').'/confirm')->assertOk();
    } finally {
        $this->travelBack();
    }
});

it('recreates an expired keyed hold without replaying the expired object', function () {
    $payload = $this->payload + ['hold_seconds' => 60];
    $headers = ['Idempotency-Key' => 'expired-retry'];
    $old = $this->postJson($this->path, $payload, $headers)->assertCreated();
    DB::update('UPDATE bookings SET expires_at = now() WHERE id = ?', [$old->json('id')]);
    $new = $this->postJson($this->path, $payload, $headers)->assertCreated();
    expect($new->json('id'))->not->toBe($old->json('id'));
    $this->assertDatabaseMissing('bookings', ['id' => $old->json('id')]);
});

it('keeps cleanup resource scoped and rolls it back with a failed insertion', function () {
    $other = Resource::factory()->create();
    $expired = $this->postJson($this->path, $this->payload + ['hold_seconds' => 60])->assertCreated();
    DB::update('UPDATE bookings SET expires_at = now() WHERE id = ?', [$expired->json('id')]);
    $this->postJson("/api/resources/{$other->id}/bookings", $this->payload)->assertCreated();
    $this->assertDatabaseHas('bookings', ['id' => $expired->json('id')]);
    $later = ['starts_at' => '2026-11-01T11:00:00Z', 'ends_at' => '2026-11-01T12:00:00Z'];
    // Insert directly using the old writer's columns to verify additive defaults.
    DB::insert('INSERT INTO bookings (resource_id, external_type, external_id, during) VALUES (?, ?, ?, ?::tstzrange)', [$this->resource->id, 'test', 1, '[2026-11-01T11:00:00Z,2026-11-01T12:00:00Z)']);
    $this->postJson($this->path, $later)->assertConflict();
    $this->assertDatabaseHas('bookings', ['id' => $expired->json('id')]);
    $this->assertDatabaseMissing('expired_booking_holds', ['booking_id' => $expired->json('id')]);
});

it('enforces held and confirmed state invariants in Postgres', function (string $assignment) {
    $created = $this->postJson($this->path, $this->payload)->assertCreated();
    try {
        DB::transaction(fn () => DB::update('UPDATE bookings SET '.$assignment.' WHERE id = ?', [$created->json('id')]));
        $this->fail('PostgreSQL must reject an invalid hold state.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
})->with(["status = 'held'", 'expires_at = now()', "status = 'cancelled'", 'hold_seconds = 59']);

it('returns not found for a live hold under the wrong parent or after explicit release', function () {
    $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 60])->assertCreated();
    $other = Resource::factory()->create();
    $this->postJson("/api/resources/{$other->id}/bookings/{$hold->json('id')}/confirm")->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->deleteJson($this->path.'/'.$hold->json('id'))->assertNoContent();
    $this->postJson($this->path.'/'.$hold->json('id').'/confirm')->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('prunes only resource receipts older than 24 hours on the next cleanup', function (string $method) {
    $hold = $this->postJson($this->path, $this->payload + ['hold_seconds' => 60])->assertCreated();
    DB::update('UPDATE bookings SET expires_at = now() WHERE id = ?', [$hold->json('id')]);
    $current = $this->postJson($this->path, $this->payload)->assertCreated();
    $expiredUri = $this->path.'/'.$hold->json('id').'/confirm';
    $this->postJson($expiredUri)->assertGone();
    expect(DB::selectOne('SELECT created_at = now() AS database_timed FROM expired_booking_holds WHERE booking_id = ?', [$hold->json('id')])->database_timed)->toBeTrue();

    DB::update("UPDATE expired_booking_holds SET created_at = now() - interval '24 hours' - interval '1 second' WHERE booking_id = ?", [$hold->json('id')]);
    $other = Resource::factory()->create();
    DB::insert("INSERT INTO expired_booking_holds (booking_id, resource_id, created_at) VALUES
        (?, ?, now() - interval '1 hour'), (?, ?, now() - interval '24 hours'), (?, ?, now() - interval '25 hours')",
        [900001, $this->resource->id, 900002, $this->resource->id, 900003, $other->id]);
    $target = ['starts_at' => '2026-11-01T11:00:00Z', 'ends_at' => '2026-11-01T12:00:00Z'];
    if ($method === 'post') {
        $this->postJson($this->path, $target)->assertCreated();
    } else {
        $this->patchJson($this->path.'/'.$current->json('id'), $target, ['If-Match' => '"1"'])->assertOk();
    }
    $this->assertDatabaseMissing('expired_booking_holds', ['booking_id' => $hold->json('id')]);
    foreach ([900001, 900002, 900003] as $id) {
        $this->assertDatabaseHas('expired_booking_holds', ['booking_id' => $id]);
    }
    $this->postJson($expiredUri)->assertNotFound()->assertJsonPath('code', 'not_found')
        ->assertOpenApi('/resources/{resource}/bookings/{booking}/confirm', 'post');
    $this->postJson($this->path.'/900001/confirm')->assertGone()->assertJsonPath('code', 'hold_expired');
})->with(['post', 'patch']);
