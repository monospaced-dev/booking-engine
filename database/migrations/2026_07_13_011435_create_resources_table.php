<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->string('external_type');
            $table->unsignedBigInteger('external_id');
            $table->timestamps();

            $table->unique(['external_type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
