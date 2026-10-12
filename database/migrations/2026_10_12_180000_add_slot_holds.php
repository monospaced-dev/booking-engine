<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE bookings
            ADD COLUMN status text NOT NULL DEFAULT 'confirmed',
            ADD COLUMN expires_at timestamptz NULL,
            ADD COLUMN hold_seconds integer NULL,
            ADD CONSTRAINT bookings_hold_state_check CHECK (
                (status = 'confirmed' AND expires_at IS NULL) OR
                (status = 'held' AND expires_at IS NOT NULL)),
            ADD CONSTRAINT bookings_hold_seconds_check CHECK (hold_seconds BETWEEN 60 AND 900)");
        // Retain only identity, so confirmation distinguishes cleaned expiry from
        // a missing booking without retaining customer data or blocking ranges.
        DB::statement('CREATE TABLE expired_booking_holds (
            booking_id bigint PRIMARY KEY,
            resource_id bigint NOT NULL REFERENCES resources(id) ON DELETE CASCADE)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE expired_booking_holds');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_hold_state_check,
            DROP CONSTRAINT bookings_hold_seconds_check,
            DROP COLUMN status, DROP COLUMN expires_at, DROP COLUMN hold_seconds');
    }
};
