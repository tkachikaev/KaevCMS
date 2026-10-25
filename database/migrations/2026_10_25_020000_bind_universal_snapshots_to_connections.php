<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only: published 0.53.8 catalog migration remains unchanged.
        Schema::table('universal_driver_connection_snapshots', function (Blueprint $table): void {
            $table->char('connection_fingerprint', 64)->nullable()->after('profile_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('universal_driver_connection_snapshots', function (Blueprint $table): void {
            $table->dropColumn('connection_fingerprint');
        });
    }
};
