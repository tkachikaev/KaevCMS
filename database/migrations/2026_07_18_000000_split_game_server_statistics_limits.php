<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('statistics_level_limit')->default(10);
            $table->unsignedSmallInteger('statistics_pvp_limit')->default(10);
            $table->unsignedSmallInteger('statistics_pk_limit')->default(10);
            $table->unsignedSmallInteger('statistics_play_time_limit')->default(10);
        });

        DB::table('game_servers')
            ->select(['id', 'statistics_limit'])
            ->orderBy('id')
            ->get()
            ->each(function (object $server): void {
                $limit = min(max((int) $server->statistics_limit, 1), 100);

                DB::table('game_servers')
                    ->where('id', (int) $server->id)
                    ->update([
                        'statistics_level_limit' => $limit,
                        'statistics_pvp_limit' => $limit,
                        'statistics_pk_limit' => $limit,
                        'statistics_play_time_limit' => $limit,
                    ]);
            });

        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropColumn('statistics_limit');
        });
    }

    public function down(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('statistics_limit')->default(10);
        });

        DB::table('game_servers')
            ->select(['id', 'statistics_level_limit'])
            ->orderBy('id')
            ->get()
            ->each(function (object $server): void {
                DB::table('game_servers')
                    ->where('id', (int) $server->id)
                    ->update([
                        'statistics_limit' => min(max((int) $server->statistics_level_limit, 1), 100),
                    ]);
            });

        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropColumn([
                'statistics_level_limit',
                'statistics_pvp_limit',
                'statistics_pk_limit',
                'statistics_play_time_limit',
            ]);
        });
    }
};
