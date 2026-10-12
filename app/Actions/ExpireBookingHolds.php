<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class ExpireBookingHolds
{
    /** Called inside the writer's transaction; DELETE rechecks after row locks. */
    public static function handle(int $resourceId): void
    {
        DB::statement("WITH pruned AS (
            DELETE FROM expired_booking_holds
            WHERE resource_id = ? AND created_at < now() - interval '24 hours'
        ), expired AS (
            DELETE FROM bookings WHERE resource_id = ? AND status = 'held' AND expires_at <= now()
            RETURNING id, resource_id
        ) INSERT INTO expired_booking_holds (booking_id, resource_id)
          SELECT id, resource_id FROM expired ON CONFLICT DO NOTHING", [$resourceId, $resourceId]);
    }
}
