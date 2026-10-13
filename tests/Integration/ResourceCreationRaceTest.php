<?php

use App\Models\ApiKey;
use App\Models\Resource;
use App\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\RaceConnection;

uses(DatabaseMigrations::class);

it('recovers resource creation races by identity alone', function () {
    $headers = apiKeyHeader();
    $identity = ['external_type' => 'race', 'external_id' => 123];
    $tenantId = Tenant::where('name', 'default')->value('id');
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
    $connection->beforeExecuting(function (string $query) use (&$started, $pipes, $connection, $requestPid, $tenantId) {
        if ($started || ! str_starts_with($query, 'insert into "resources"')) {
            return;
        }
        $started = true;
        fwrite($pipes[0], json_encode([
            'mode' => 'resource', 'connection' => $connection->getConfig(), 'request_pid' => $requestPid,
            'bindings' => ['race', 123, $tenantId],
        ], JSON_THROW_ON_ERROR)."\n");
        fclose($pipes[0]);
        expect(trim(fgets($pipes[1])))->toMatch('/^[0-9]+$/');
    });
    try {
        $response = $this->withHeaders($headers)->postJson('/api/resources', $identity);
        expect($started)->toBeTrue();
        expect(trim(stream_get_contents($pipes[1])))->toBe('committed after observing blocked retry');
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($worker);
        $worker = null;
        expect($exit)->toBe(0, $error);
        expect($connection->observedSqlstate)->toBe('23505');
        $response->assertOk()->assertOpenApi('/resources', 'post')->assertJsonPath('created', false)->assertJsonMissingPath('timezone');
        expect(Resource::where($identity)->count())->toBe(1);
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
        Connection::resolverFor('pgsql', $originalResolver ?? fn ($pdo, $database, $prefix, $config) => new PostgresConnection($pdo, $database, $prefix, $config));
        DB::purge();
        Resource::where($identity)->delete();
        ApiKey::where('key', ApiKey::hashKey($headers['X-API-Key']))->delete();
    }
});
