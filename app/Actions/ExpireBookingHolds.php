<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class ExpireBookingHolds
{
    /** Called inside the writer's transaction; DELETE rechecks after row locks. */
    public static function handle(int $resourceId): void
    {
        DB::statement("WITH expired AS (
            DELETE FROM bookings WHERE resource_id = ? AND status = 'held' AND expires_at <= now()
            RETURNING id, resource_id
        ) INSERT INTO expired_booking_holds (booking_id, resource_id)
          SELECT id, resource_id FROM expired ON CONFLICT DO NOTHING", [$resourceId]);
    }
}
