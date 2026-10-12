<?php

namespace App\Actions;

use App\Helpers\Interval;
use App\Models\Blackout;
use App\Models\Booking;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GetBusyRanges
{
    /** Get merged busy times from bookings and blackouts, clipped to the window. */
    public function handle(Resource $resource, Carbon $from, Carbon $to): array
    {
        $ranges = $resource->bookings()->overlapping($from, $to)->get()
            ->concat($resource->blackouts()->overlapping($from, $to)->get());

        return $this->merge($ranges, $from, $to);
    }

    /** Fetch both busy sources once, then merge each requested resource in memory. */
    public function handleBatch(array $resourceIds, Carbon $from, Carbon $to): array
    {
        $ranges = Booking::whereIn('resource_id', $resourceIds)->overlapping($from, $to)->get()
            ->concat(Blackout::whereIn('resource_id', $resourceIds)->overlapping($from, $to)->get())
            ->groupBy('resource_id');

        $busy = [];
        foreach ($resourceIds as $id) {
            $busy[$id] = $this->merge($ranges->get($id, collect()), $from, $to);
        }

        return $busy;
    }

    private function merge(Collection $ranges, Carbon $from, Carbon $to): array
    {
        $exclusions = $ranges->map(function ($range) {
            $parsed = $range->parseDuring($range->during);

            return ['start' => $parsed['starts_at'], 'end' => $parsed['ends_at']];
        })->all();

        return Interval::mergeExclusions(['start' => $from, 'end' => $to], $exclusions);
    }
}
