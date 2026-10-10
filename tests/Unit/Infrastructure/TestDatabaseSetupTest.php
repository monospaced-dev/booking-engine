<?php

use Symfony\Component\Process\Process;

it('stops once with setup instructions when the test database is unavailable', function () {
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/pest', '--testsuite=Feature', '--colors=never'],
        dirname(__DIR__, 3),
        ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1'],
    );
    $process->setTimeout(30);
    $process->run();
    $output = $process->getOutput().$process->getErrorOutput();

    expect(substr_count($output, 'Test database unavailable:'))->toBe(1);
    expect($process->getExitCode())->toBe(1);
    expect($output)->toContain('Postgres 16', 'booking_engine_test', 'DB_HOST', 'DB_PORT');
    expect($output)->not->toContain('SQLSTATE', 'QueryException');
});
