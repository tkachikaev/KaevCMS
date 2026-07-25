<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('game_servers', 'maintenance_until')) {
            return;
        }

        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropColumn('maintenance_until');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('game_servers', 'maintenance_until')) {
            return;
        }

        Schema::table('game_servers', function (Blueprint $table): void {
            $table->timestamp('maintenance_until')->nullable();
        });
    }
};
