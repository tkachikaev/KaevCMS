<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_daily_reward_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('day_id')->constrained('module_daily_reward_days')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('amount');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['day_id', 'item_id'], 'module_daily_reward_day_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_daily_reward_items');
    }
};
