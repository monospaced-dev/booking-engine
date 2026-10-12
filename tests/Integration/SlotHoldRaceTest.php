<?php

use App\Models\ApiKey;
use App\Models\Resource;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('serializes confirmation against expiry cleanup with one slot winner', function (string $mode) {
    $resource = Resource::factory()->create();
    $headers = apiKeyHeader();
    $this->withHeaders($headers);
    $path = "/api/resources/{$resource->id}/bookings";
    $payload = ['starts_at' => '2026-08-03T09:00:00Z', 'ends_at' => '2026-08-03T10:00:00Z'];
    $hold = $this->postJson($path, $payload + ['hold_seconds' => 60])->assertCreated();
    $uri = $path.'/'.$hold->json('id').'/confirm';
    $connection = DB::connection();
    $requestPid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $worker = proc_open([PHP_BINARY, base_path('tests/Support/booking-race-worker.php')], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    expect(is_resource($worker))->toBeTrue();
    stream_set_timeout($pipes[1], 15);
    stream_set_timeout($pipes[2], 15);
    fwrite($pipes[0], json_encode([
        'connection' => $connection->getConfig(), 'request_pid' => $requestPid,
        'mode' => $mode, 'uri' => $uri, 'payload' => $payload, 'headers' => $headers,
        'booking_id' => $hold->json('id'), 'resource_id' => $resource->id, 'path' => $path,
    ], JSON_THROW_ON_ERROR)."\n");
    fclose($pipes[0]);

    try {
        expect(trim(fgets($pipes[1])))->toBe($mode === 'hold-confirm' ? '200' : '201');
        $response = $mode === 'hold-confirm' ? $this->postJson($path, $payload) : $this->postJson($uri);
        $response->assertStatus($mode === 'hold-confirm' ? 409 : 410);
        $result = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        expect($result)->toBe('committed after observing blocked retry', $error);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($worker))->toBe(0, $error);
        $worker = null;
        expect($resource->bookings()->count())->toBe(1);
        expect($resource->bookings()->first()->status)->toBe('confirmed');
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
})->with(['hold-confirm', 'hold-insert']);
