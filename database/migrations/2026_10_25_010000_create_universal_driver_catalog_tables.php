<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only local overrides/custom profiles and deletion markers are stored here.
        // Bundled release JSON remains immutable and is never copied on installation.
        Schema::create('universal_driver_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 8);
            $table->string('profile_id', 64);
            $table->longText('profile_json')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->unique(['type', 'profile_id'], 'universal_profile_identity_unique');
        });

        // Future driver integration applies a checked configuration to each connection.
        // Never modify existing login_servers/game_servers columns or credentials here.
        Schema::create('universal_driver_connection_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('connection_type', 8);
            $table->unsignedBigInteger('connection_id');
            $table->string('profile_id', 64);
            $table->unsignedSmallInteger('format_version');
            $table->char('profile_sha256', 64);
            $table->longText('profile_json');
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['connection_type', 'connection_id'], 'universal_snapshot_connection_unique');
            $table->index(['connection_type', 'profile_id'], 'universal_snapshot_profile_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universal_driver_connection_snapshots');
        Schema::dropIfExists('universal_driver_profiles');
    }
};
