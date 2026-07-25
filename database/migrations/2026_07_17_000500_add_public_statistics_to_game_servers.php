<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->boolean('statistics_enabled')->default(false)->index();
            $table->boolean('statistics_level_enabled')->default(true);
            $table->boolean('statistics_pvp_enabled')->default(true);
            $table->boolean('statistics_pk_enabled')->default(true);
            $table->boolean('statistics_play_time_enabled')->default(true);
            $table->boolean('statistics_heroes_enabled')->default(true);
            $table->boolean('statistics_castles_enabled')->default(true);
            $table->unsignedSmallInteger('statistics_limit')->default(50);
        });
    }

    public function down(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropIndex(['statistics_enabled']);
            $table->dropColumn([
                'statistics_enabled',
                'statistics_level_enabled',
                'statistics_pvp_enabled',
                'statistics_pk_enabled',
                'statistics_play_time_enabled',
                'statistics_heroes_enabled',
                'statistics_castles_enabled',
                'statistics_limit',
            ]);
        });
    }
};
