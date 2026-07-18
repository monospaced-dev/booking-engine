<?php

namespace App\Actions;

use App\Exceptions\BookingConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Models\Booking;
use Carbon\Carbon;

class CreateBooking {
    /**
     * @throws BookingConflictException
     */
    public function handle(int $resourceId, string $externalType, int $externalId, Carbon $startsAt, Carbon $endsAt, array $metadata = []): Booking
    {
        $during = sprintf(
            '[%s,%s)',
            $startsAt->format('Y-m-d H:i:sP'),
            $endsAt->format('Y-m-d H:i:sP')
        );

        try {
            $row = DB::selectOne(
                'INSERT INTO bookings (resource_id, external_type, external_id, during, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?::tstzrange, ?, now(), now())
             RETURNING id, resource_id, external_type, external_id, during, metadata, created_at, updated_at',
                [
                    $resourceId,
                    $externalType,
                    $externalId,
                    $during,
                    json_encode($metadata),
                ]
            );
        } catch (QueryException $e) {
            // SQLSTATE 23P01 = exclusion_violation
            if ($e->getCode() === '23P01') {
                throw new BookingConflictException("Requested time overlaps an existing booking for this resource.");
            }

            throw $e;
        }

        $booking =  new Booking([
            'id' => $row->id,
            'resource_id' => $row->resource_id,
            'external_type' => $row->external_type,
            'external_id' => $row->external_id,
            'metadata' => json_decode($row->metadata, true),
        ]);

        $parsed = $booking->parseDuring($row->during);

        $booking->setAttribute('starts_at', $parsed['starts_at']);
        $booking->setAttribute('ends_at', $parsed['ends_at']);

        return $booking;
    }
}

