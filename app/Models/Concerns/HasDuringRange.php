<?php

namespace App\Models\Concerns;

use App\Helpers\BookingRange;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

trait HasDuringRange
{
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereRaw('during && ?::tstzrange', [BookingRange::literal($from, $to)]);
    }

    /**
     * Parse the during tstzrange column into starts_at and ends_at Carbon instances.
     * example: ["2026-07-14 09:00:00+00","2026-07-14 10:00:00+00")
     *
     * @param  string  $rawDuring  Raw PostgreSQL range bounds.
     */
    public function parseDuring(string $rawDuring): array
    {
        $stripped = str_replace(['[', '(', ']', ')', '"'], '', $rawDuring);
        $parts = explode(',', $stripped);

        $startsAt = Carbon::parse($parts[0])->utc();
        $endsAt = Carbon::parse($parts[1])->utc();

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }
}
