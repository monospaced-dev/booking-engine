<?php

use App\Models\ApiKey;
use App\Models\Resource;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('waits for a real competing transaction before resolving PATCH', function (string $mode, int $status, string $code) {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();
    $this->withHeaders($headers);
    $path = "/api/resources/{$resource->id}/bookings";
    $original = ['starts_at' => '2026-08-03T09:00:00Z', 'ends_at' => '2026-08-03T10:00:00Z'];
    $created = $this->postJson($path, $original)->assertCreated();
    $uri = $path.'/'.$created->json('id');
    $target = ['starts_at' => '2026-08-03T11:00:00Z', 'ends_at' => '2026-08-03T12:00:00Z'];
    $before = (array) DB::table('bookings')->find($created->json('id'));
    $connection = DB::connection();
    $requestPid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $worker = proc_open([PHP_BINARY, base_path('tests/Support/booking-race-worker.php')], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    expect(is_resource($worker))->toBeTrue();
    stream_set_timeout($pipes[1], 15);
    stream_set_timeout($pipes[2], 15);
    $started = false;
    $connection->beforeExecuting(function (string $query) use (&$started, $pipes, $connection, $resource, $target, $requestPid, $mode, $uri, $headers) {
        if ($started || ! str_starts_with($query, 'UPDATE bookings SET during')) {
            return;
        }
        $started = true;
        fwrite($pipes[0], json_encode([
            'connection' => $connection->getConfig(), 'request_pid' => $requestPid,
            'mode' => $mode, 'uri' => $uri, 'payload' => $target, 'headers' => $headers + ['If-Match' => '"1"'],
            'bindings' => [$resource->id, $resource->external_type, $resource->external_id,
                '[2026-08-03T11:00:00Z,2026-08-03T12:00:00Z)', '[]', null],
        ], JSON_THROW_ON_ERROR)."\n");
        fclose($pipes[0]);
        $ready = trim(fgets($pipes[1]));
        if ($mode === 'patch') {
            expect($ready)->toBe('200');
        } else {
            expect($ready)->toMatch('/^[0-9]+$/');
        }
    });

    try {
        $response = $this->patchJson($uri, $target, ['If-Match' => '"1"']);
        expect($started)->toBeTrue();
        expect(trim(stream_get_contents($pipes[1])))->toBe('committed after observing blocked retry');
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($worker);
        $worker = null;
        expect($exitCode)->toBe(0, $error);
        $response->assertStatus($status)->assertJsonPath('code', $code)->assertOpenApi('/resources/{resource}/bookings/{booking}', 'patch');
        if ($mode === 'insert') {
            expect((array) DB::table('bookings')->find($created->json('id')))->toBe($before);
        } else {
            $this->getJson($uri)->assertOk()->assertJsonPath('lock_version', 2)->assertHeader('ETag', '"2"')
                ->assertJsonPath('starts_at', '2026-08-03T11:00:00+00:00');
        }
    } finally {
        if (is_resource($worker)) {
            proc_terminate($worker);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker);
        }
        DB::purge();
        $resource->delete();
        ApiKey::where('key', ApiKey::hashKey($headers['X-API-Key']))->delete();
    }
})->with([
    'uncommitted conflicting insert' => ['insert', 409, 'booking_conflict'],
    'two PATCHes with the same version' => ['patch', 412, 'precondition_failed'],
]);
