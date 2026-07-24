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
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('resource_id');
        });

        // Partial unique index — only enforced when a key is actually provided,
        // so callers that don't send one aren't constrained against each other.
        DB::statement(
            'CREATE UNIQUE INDEX bookings_resource_idempotency_key_unique
         ON bookings (resource_id, idempotency_key)
         WHERE idempotency_key IS NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bookings_resource_idempotency_key_unique');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
