<?php

namespace App\Actions;

use App\Exceptions\BookingConflictException;
use App\Helpers\BookingRange;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RescheduleBooking
{
    public function handle(int $resourceId, int $bookingId, string $version, Carbon $startsAt, Carbon $endsAt): ?Booking
    {
        try {
            // A savepoint also protects callers inside an enclosing transaction.
            $row = DB::transaction(function () use ($resourceId, $bookingId, $version, $startsAt, $endsAt) {
                ExpireBookingHolds::handle($resourceId);

                return DB::selectOne(
                    'UPDATE bookings SET during = ?::tstzrange, updated_at = clock_timestamp(), lock_version = lock_version + 1
                 WHERE id = ? AND resource_id = ? AND lock_version = ?
                 RETURNING *',
                    [BookingRange::literal($startsAt, $endsAt), $bookingId, $resourceId, $version],
                );
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') {
                throw new BookingConflictException('Requested time overlaps an existing booking for this resource.');
            }
            throw $e;
        }

        return $row === null ? null : (new Booking)->newFromBuilder((array) $row);
    }
}
