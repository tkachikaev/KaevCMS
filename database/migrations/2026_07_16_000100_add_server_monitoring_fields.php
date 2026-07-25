<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_servers', function (Blueprint $table): void {
            $table->string('service_host', 255)->nullable();
            $table->unsignedSmallInteger('service_port')->nullable();
            $table->string('monitor_status', 16)->default('unknown')->index();
            $table->unsignedSmallInteger('monitor_failures')->default(0);
            $table->timestamp('monitor_checked_at')->nullable();
            $table->timestamp('monitor_last_online_at')->nullable();
        });

        Schema::table('game_servers', function (Blueprint $table): void {
            $table->string('service_host', 255)->nullable();
            $table->unsignedSmallInteger('service_port')->nullable();
            $table->string('monitor_status', 16)->default('unknown')->index();
            $table->unsignedSmallInteger('monitor_failures')->default(0);
            $table->timestamp('monitor_checked_at')->nullable();
            $table->timestamp('monitor_last_online_at')->nullable();
            $table->unsignedInteger('online_players')->nullable();
            $table->timestamp('online_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropIndex(['monitor_status']);
            $table->dropColumn([
                'service_host',
                'service_port',
                'monitor_status',
                'monitor_failures',
                'monitor_checked_at',
                'monitor_last_online_at',
                'online_players',
                'online_checked_at',
            ]);
        });

        Schema::table('login_servers', function (Blueprint $table): void {
            $table->dropIndex(['monitor_status']);
            $table->dropColumn([
                'service_host',
                'service_port',
                'monitor_status',
                'monitor_failures',
                'monitor_checked_at',
                'monitor_last_online_at',
            ]);
        });
    }
};
