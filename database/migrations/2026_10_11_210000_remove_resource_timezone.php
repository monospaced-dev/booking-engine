<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }

    public function down(): void
    {
        // Structure only: former resource timezone values are not recoverable.
        Schema::table('resources', fn (Blueprint $table) => $table->string('timezone')->default('UTC'));
    }
};
