<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;

class RaceConnection extends PostgresConnection
{
    public ?string $observedSqlstate = null;

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $exception) {
            $this->observedSqlstate = $exception->getCode();
            throw $exception;
        }
    }
}
