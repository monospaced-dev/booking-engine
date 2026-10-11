<?php

namespace App\Models\Concerns;

use Carbon\Carbon;
use DateTimeInterface;

trait SerializesUtcTimestamps
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)->utc()->toIso8601String();
    }
}
