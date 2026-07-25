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
        Schema::table('blackout_dates', function (Blueprint $table) {
            $table->renameColumn('reason', 'note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blackout_dates', function (Blueprint $table) {
            $table->renameColumn('note', 'reason');
        });
    }
};
