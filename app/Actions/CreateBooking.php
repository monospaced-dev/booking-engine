<?php

namespace App\Actions;

use App\Exceptions\BookingConflictException;
use App\Helpers\BookingRange;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateBooking
{
    /**
     * @throws BookingConflictException
     */
    public function handle(
        int $resourceId,
        string $externalType,
        int $externalId,
        Carbon $startsAt,
        Carbon $endsAt,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): Booking {
        $during = BookingRange::literal($startsAt, $endsAt);

        try {
            // Wrapped in DB::transaction() so a failed INSERT rolls back to a
            // savepoint rather than aborting any enclosing transaction
            $row = DB::transaction(function () use ($resourceId, $externalType, $externalId, $during, $metadata, $idempotencyKey) {
                return DB::selectOne(
                    'INSERT INTO bookings (resource_id, external_type, external_id, during, metadata, idempotency_key, created_at, updated_at)
                 VALUES (?, ?, ?, ?::tstzrange, ?, ?, now(), now())
                 RETURNING lock_version, id, resource_id, external_type, external_id, during, metadata, idempotency_key, created_at, updated_at',
                    [
                        $resourceId,
                        $externalType,
                        $externalId,
                        $during,
                        json_encode((object) $metadata),
                        $idempotencyKey,
                    ]
                );
            });
        } catch (QueryException $e) {
            // SQLSTATE 23P01 = exclusion_violation
            if ($e->getCode() === '23P01') {
                throw new BookingConflictException('Requested time overlaps an existing booking for this resource.');
            }

            throw $e;
        }

        $booking = (new Booking)->newFromBuilder((array) $row);

        $parsed = $booking->parseDuring($row->during);

        $booking->setAttribute('starts_at', $parsed['starts_at']);
        $booking->setAttribute('ends_at', $parsed['ends_at']);

        return $booking;
    }
}
