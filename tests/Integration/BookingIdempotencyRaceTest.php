<?php

use App\Models\ApiKey;
use App\Models\Booking;
use App\Models\Resource;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\RaceConnection;

uses(DatabaseMigrations::class);

it('recovers the real constraint race after an uncommitted booking wins', function (array $winnerChanges, ?string $winnerKey, int $status, ?string $code, string $sqlstate) {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();
    $payload = ['starts_at' => '2026-08-03T14:00:00Z', 'ends_at' => '2026-08-03T15:00:00Z', 'metadata' => ['appointment_id' => 7]];
    $winner = array_replace($payload, $winnerChanges);
    $originalResolver = Connection::getResolver('pgsql');
    Connection::resolverFor('pgsql', fn ($pdo, $database, $prefix, $config) => new RaceConnection($pdo, $database, $prefix, $config));
    DB::purge();
    $connection = DB::connection();
    $requestPid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $worker = proc_open([PHP_BINARY, base_path('tests/Support/booking-race-worker.php')], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    expect(is_resource($worker))->toBeTrue();
    stream_set_timeout($pipes[1], 15);
    stream_set_timeout($pipes[2], 15);
    $started = false;

    // The worker starts only after the replay pre-check: its row is invisible
    // until the HTTP INSERT blocks and the worker commits it.
    $connection->beforeExecuting(function (string $query) use (&$started, $pipes, $connection, $resource, $winner, $winnerKey, $requestPid) {
        if ($started || ! str_starts_with($query, 'INSERT INTO bookings')) {
            return;
        }
        $started = true;
        fwrite($pipes[0], json_encode([
            'connection' => $connection->getConfig(), 'request_pid' => $requestPid,
            'bindings' => [$resource->id, $resource->external_type, $resource->external_id,
                sprintf('[%s,%s)', $winner['starts_at'], $winner['ends_at']), json_encode($winner['metadata']), $winnerKey],
        ], JSON_THROW_ON_ERROR)."\n");
        fclose($pipes[0]);
        expect(trim(fgets($pipes[1])))->toMatch('/^[0-9]+$/');
    });

    try {
        $response = $this->withHeaders($headers)->postJson("/api/resources/{$resource->id}/bookings", $payload, ['Idempotency-Key' => 'race-key']);
        expect($started)->toBeTrue('HTTP request must reach the real INSERT.');
        $workerResult = trim(stream_get_contents($pipes[1]));
        expect($workerResult)->toBe('committed after observing blocked retry');
        $workerError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($worker);
        $worker = null;
        expect($exitCode)->toBe(0, $workerError);
        expect($connection->observedSqlstate)->toBe($sqlstate);
        $response->assertStatus($status)->assertOpenApi('/resources/{resource}/bookings', 'post');
        if ($code !== null) {
            $response->assertJsonPath('code', $code);
        } else {
            $stored = $this->withHeaders($headers)->getJson("/api/resources/{$resource->id}/bookings")->assertOk();
            $response->assertExactJson($stored->json(0));
        }
        expect(Booking::where('resource_id', $resource->id)->count())->toBe(1);
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
        // Purge callbacks before cleanup and the next test; delete committed
        // fixtures explicitly, even if assertions fail.
        Connection::resolverFor('pgsql', $originalResolver ?? fn ($pdo, $database, $prefix, $config) => new PostgresConnection($pdo, $database, $prefix, $config));
        DB::purge();
        $resource->delete();
        ApiKey::where('key', ApiKey::hashKey($headers['X-API-Key']))->delete();
    }
})->with([
    'matching exclusion race' => [[], 'race-key', 200, null, '23P01'],
    'different start exclusion race' => [['starts_at' => '2026-08-03T14:10:00Z'], 'race-key', 422, 'idempotency_mismatch', '23P01'],
    'different end exclusion race' => [['ends_at' => '2026-08-03T15:10:00Z'], 'race-key', 422, 'idempotency_mismatch', '23P01'],
    'different metadata exclusion race' => [['metadata' => ['appointment_id' => 8]], 'race-key', 422, 'idempotency_mismatch', '23P01'],
    'different key conflict' => [[], 'other-key', 409, 'booking_conflict', '23P01'],
    'unkeyed winner conflict' => [[], null, 409, 'booking_conflict', '23P01'],
    'disjoint unique race' => [['starts_at' => '2026-08-03T16:00:00Z', 'ends_at' => '2026-08-03T17:00:00Z'], 'race-key', 422, 'idempotency_mismatch', '23505'],
]);
