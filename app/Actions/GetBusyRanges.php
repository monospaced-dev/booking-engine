<?php

namespace App\Actions;


use App\Helpers\Interval;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GetBusyRanges
{
    /**
     * Get busy times in given time period for resource excluding bookings and blackouts.
     *
     * @param Resource $resource
     * @param Carbon $from
     * @param Carbon $to
     * @return array
     */
    public function handle(Resource $resource, Carbon $from, Carbon $to): array
    {
        $bookings = $resource->bookings()
            ->whereRaw('during && ?::tstzrange', [
                sprintf('[%s,%s)', $from->toIso8601String(), $to->toIso8601String())
            ])
            ->get();

        $blackouts = $resource->blackoutDates()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $bookingExclusions = $this->bookingsToExclusions($bookings);
        $blackoutExclusions = $this->blackoutsToExclusions($blackouts, $resource->timezone);
        $exclusions = array_merge($bookingExclusions, $blackoutExclusions);

        return Interval::mergeExclusions(['start' => $from, 'end' => $to], $exclusions);
    }


    private function bookingsToExclusions(Collection $bookings): array
    {
        return $bookings->map(function ($booking)  {
            $parsed = $booking->parseDuring($booking->during);
            return ['start' => $parsed['starts_at'], 'end' => $parsed['ends_at']];
        })->all();
    }

    private function blackoutsToExclusions(Collection $blackouts, string $timezone): array
    {
        return $blackouts->map(function ($blackout) use ($timezone) {
            $date = $blackout->date;

            if ($blackout->start_time === null || $blackout->end_time === null) {
                $start = Carbon::parse($date, $timezone)->startOfDay()->utc();
                $end = Carbon::parse($date, $timezone)->addDay()->startOfDay()->utc();
            } else {
                $start = Carbon::parse("{$date} $blackout->start_time", $timezone)->utc();
                $end = Carbon::parse("{$date} $blackout->end_time", $timezone)->utc();
            }

            return ['start' => $start, 'end' => $end];
        })->all();
    }
}
