<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('external_type');
            $table->unsignedBigInteger('external_id');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        // tstzrange column + exclusion constraint aren't expressible via
        // Blueprint, so raw SQL for this part.
        DB::statement('ALTER TABLE bookings ADD COLUMN during tstzrange NOT NULL');
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
             EXCLUDE USING gist (resource_id WITH =, during WITH &&)'
        );

        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['external_type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
