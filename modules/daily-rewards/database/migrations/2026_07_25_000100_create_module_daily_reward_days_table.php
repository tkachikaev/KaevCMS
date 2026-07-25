<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_daily_reward_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained('module_daily_reward_calendars')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_number');
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['calendar_id', 'day_number'], 'module_daily_reward_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_daily_reward_days');
    }
};
