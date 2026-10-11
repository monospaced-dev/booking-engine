<?php

namespace App\Actions;

use App\Helpers\Interval;
use App\Models\Resource;
use Carbon\Carbon;

class GetBusyRanges
{
    /** Get merged busy times from bookings and blackouts, clipped to the window. */
    public function handle(Resource $resource, Carbon $from, Carbon $to): array
    {
        $ranges = $resource->bookings()->overlapping($from, $to)->get()
            ->concat($resource->blackouts()->overlapping($from, $to)->get());
        $exclusions = $ranges->map(function ($range) {
            $parsed = $range->parseDuring($range->during);

            return ['start' => $parsed['starts_at'], 'end' => $parsed['ends_at']];
        })->all();

        return Interval::mergeExclusions(['start' => $from, 'end' => $to], $exclusions);
    }
}
