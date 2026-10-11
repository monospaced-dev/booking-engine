<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blackouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE blackouts ADD COLUMN during tstzrange NOT NULL');
        DB::statement('CREATE INDEX blackouts_resource_during_gist ON blackouts USING gist (resource_id, during)');
        DB::statement('ALTER TABLE blackouts ADD CONSTRAINT blackouts_during_finite_nonempty
            CHECK (NOT isempty(during) AND NOT lower_inf(during) AND NOT upper_inf(during)
                AND isfinite(lower(during)) AND isfinite(upper(during)))');

        // Convert both local boundaries independently: full DST days may be 23 or 25 hours.
        // Match the legacy busy path, which treats either missing time as a full day.
        DB::statement("INSERT INTO blackouts (resource_id, during, note, created_at, updated_at)
            SELECT b.resource_id,
                tstzrange(
                    (CASE WHEN b.start_time IS NULL OR b.end_time IS NULL THEN b.date::timestamp
                        ELSE b.date + b.start_time END) AT TIME ZONE r.timezone,
                    (CASE WHEN b.start_time IS NULL OR b.end_time IS NULL THEN (b.date + 1)::timestamp
                        ELSE b.date + b.end_time END) AT TIME ZONE r.timezone,
                    '[)'),
                b.note, b.created_at, b.updated_at
            FROM blackout_dates b JOIN resources r ON r.id = b.resource_id ORDER BY b.id");
    }

    public function down(): void
    {
        Schema::dropIfExists('blackouts');
    }
};
