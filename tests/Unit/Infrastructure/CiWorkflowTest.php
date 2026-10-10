<?php

it('gates image publishing on Pest with Postgres 16', function () {
    $workflow = file_get_contents(__DIR__.'/../../../.github/workflows/build-sign.yml');

    expect($workflow)->toMatch('/^  test:\n/m');
    expect($workflow)->toMatch('/image: postgres:16/');
    expect($workflow)->toContain('POSTGRES_DB: booking_engine_test');
    expect($workflow)->toContain('pg_isready');
    expect($workflow)->toContain('pdo_pgsql');
    expect($workflow)->toContain('run: vendor/bin/pest');
    expect($workflow)->toMatch('/^  image:\n    needs: test\n/m');
});
