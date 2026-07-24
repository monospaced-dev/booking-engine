<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = ['resource_id', 'external_type', 'external_id', 'metadata', 'id', 'starts_at', 'ends_at', 'idempotency_key'];

    protected $casts = ['metadata' => 'array'];

    protected $hidden = ['during'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * Parse the during tstzrange column into starts_at and ends_at Carbon instances.
     * example: ["2026-07-14 09:00:00+00","2026-07-14 10:00:00+00")
     * @param string $rawDuring Raw date/time of start and end in Google's format.
     *
     * @return array
     */
    public function parseDuring(string $rawDuring): array
    {
        $stripped = str_replace(['[', '(', ']', ')', '"'], '', $rawDuring);
        $parts = explode(',', $stripped);

        $startsAt = Carbon::parse($parts[0])->utc();
        $endsAt = Carbon::parse($parts[1])->utc();

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt
        ];
    }
}
