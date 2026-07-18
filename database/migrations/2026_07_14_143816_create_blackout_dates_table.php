<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blackout_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE blackout_dates ADD CONSTRAINT blackout_dates_time_check CHECK (start_time IS NULL OR end_time IS NULL OR start_time < end_time)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE blackout_dates DROP CONSTRAINT IF EXISTS blackout_dates_time_check');
        Schema::dropIfExists('blackout_dates');
    }
};
