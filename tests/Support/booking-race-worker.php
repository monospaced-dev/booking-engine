<?php

// Separate process/connection: hold the winning INSERT until Postgres confirms
// the HTTP request is blocked on it, then commit. No mocked database errors.
require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Connectors\PostgresConnector;

$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$pdo = (new PostgresConnector)->connect($input['connection']);
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('INSERT INTO bookings
        (resource_id, external_type, external_id, during, metadata, idempotency_key, created_at, updated_at)
        VALUES (?, ?, ?, ?::tstzrange, ?, ?, now(), now()) RETURNING id');
    $statement->execute($input['bindings']);
    $id = $statement->fetchColumn();
    fwrite(STDOUT, $id."\n");
    fflush(STDOUT);

    $blocked = $pdo->prepare('SELECT ?::int = ANY(pg_blocking_pids(?::int))');
    $workerPid = $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
    $deadline = microtime(true) + 10;
    do {
        $blocked->execute([$workerPid, $input['request_pid']]);
        if ($blocked->fetchColumn()) {
            $pdo->commit();
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
