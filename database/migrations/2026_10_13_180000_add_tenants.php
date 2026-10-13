<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'default', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['api_keys', 'resources'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants');
            });
            DB::table($name)->update(['tenant_id' => $tenantId]);
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }
        Schema::table('resources', function (Blueprint $table) {
            $table->dropUnique(['external_type', 'external_id']);
            $table->unique(['tenant_id', 'external_type', 'external_id']);
        });
    }

    public function down(): void
    {
        // Restore uniqueness first: rollback fails atomically if tenants now share identities.
        Schema::table('resources', function (Blueprint $table) {
            $table->unique(['external_type', 'external_id']);
            $table->dropUnique(['tenant_id', 'external_type', 'external_id']);
        });
        foreach (['api_keys', 'resources'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
        }
        Schema::drop('tenants');
    }
};
