<?php

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);

$config = $app->make('config');
$connection = $config->get('database.connections.'.$config->get('database.default'));

if (($connection['driver'] ?? null) !== 'pgsql' || ($connection['database'] ?? null) !== 'booking_engine_test' || ! empty($connection['url'])) {
    fwrite(STDERR, "Test database configuration invalid: use Postgres 16 and booking_engine_test. Clear cached configuration with php artisan config:clear.\n");
    exit(1);
}

try {
    (new PostgresConnector)->connect($connection);
} catch (Throwable) {
    fwrite(STDERR, "Test database unavailable: start Postgres 16 with database booking_engine_test; check DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD (see .env.testing and docs/testing.md).\n");
    exit(1);
}
