<?php

use Prometheus\Storage\Adapter;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;
use Symfony\Component\Process\Process;

it('uses configured memory storage independently of the application environment', function () {
    config(['metrics.storage' => 'memory']);
    $this->app->detectEnvironment(fn () => 'production');
    expect(app(Adapter::class))->toBeInstanceOf(InMemory::class);
});

it('selects APCu when available and otherwise falls back to memory', function () {
    config(['metrics.storage' => 'apcu']);
    $this->app->detectEnvironment(fn () => 'production');
    $expected = extension_loaded('apcu') && apcu_enabled() ? APC::class : InMemory::class;
    expect(app(Adapter::class))->toBeInstanceOf($expected);
});

it('falls back to memory when APCu is disabled', function () {
    $process = new Process([PHP_BINARY, '-d', 'apc.enabled=0', '-r', <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('metrics.storage', 'apcu');
echo get_class($app->make(Prometheus\Storage\Adapter::class));
CODE
    ], base_path(), ['APP_ENV' => 'production']);
    $process->mustRun();
    expect(trim($process->getOutput()))->toBe(InMemory::class);
});
