<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Validate existing rows too. Compatible with the previous application's
        // finite, nonempty INSERT/UPDATE ranges; no row rewrite or duration limit.
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_during_finite_nonempty
            CHECK (NOT isempty(during) AND NOT lower_inf(during) AND NOT upper_inf(during)
                AND isfinite(lower(during)) AND isfinite(upper(during)))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_during_finite_nonempty');
    }
};
