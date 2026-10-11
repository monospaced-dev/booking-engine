<?php

// Separate process/connection: hold the winning INSERT or HTTP PATCH until Postgres confirms
// the HTTP request is blocked on it, then commit. No mocked database errors.
require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($input['mode'] ?? 'insert') === 'patch') {
    $app = require __DIR__.'/../../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $app['config']->set('database.connections.pgsql', $input['connection']);
    DB::purge();
    $connection = DB::connection();
    $pdo = $connection->getPdo();
} else {
    $pdo = (new PostgresConnector)->connect($input['connection']);
}
if (isset($connection)) {
    $connection->beginTransaction();
} else {
    $pdo->beginTransaction();
}
try {
    if (($input['mode'] ?? 'insert') === 'patch') {
        $request = Request::create($input['uri'], 'PATCH', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => $input['headers']['X-API-Key'], 'HTTP_IF_MATCH' => $input['headers']['If-Match'],
        ], content: json_encode($input['payload'], JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException($response->getContent());
        }
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        if ($body['lock_version'] !== 2 || $response->headers->get('ETag') !== '"2"') {
            throw new RuntimeException('Winning PATCH must return version 2 and its ETag.');
        }
        fwrite(STDOUT, $response->getStatusCode()."\n");
    } elseif (($input['mode'] ?? '') === 'resource') {
        $statement = $pdo->prepare('INSERT INTO resources (external_type, external_id, timezone, created_at, updated_at) VALUES (?, ?, ?, now(), now()) RETURNING id');
        $statement->execute($input['bindings']);
        fwrite(STDOUT, $statement->fetchColumn()."\n");
    } else {
        $statement = $pdo->prepare('INSERT INTO bookings
            (resource_id, external_type, external_id, during, metadata, idempotency_key, created_at, updated_at)
            VALUES (?, ?, ?, ?::tstzrange, ?, ?, now(), now()) RETURNING id');
        $statement->execute($input['bindings']);
        fwrite(STDOUT, $statement->fetchColumn()."\n");
    }
    fflush(STDOUT);

    $blocked = $pdo->prepare('SELECT ?::int = ANY(pg_blocking_pids(?::int))');
    $workerPid = $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
    $deadline = microtime(true) + 10;
    do {
        $blocked->execute([$workerPid, $input['request_pid']]);
        if ($blocked->fetchColumn()) {
            if (isset($connection)) {
                $connection->commit();
            } else {
                $pdo->commit();
            }
            fwrite(STDOUT, "committed after observing blocked retry\n");
            exit(0);
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Retry did not block on the winning transaction within 10 seconds.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
