<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_daily_reward_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('timezone', 64)->default('Europe/Moscow');
            $table->boolean('enabled')->default(false);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['game_server_id', 'year', 'month'], 'module_daily_reward_calendar_period_unique');
            $table->index(['enabled', 'year', 'month'], 'module_daily_reward_calendar_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_daily_reward_calendars');
    }
};
