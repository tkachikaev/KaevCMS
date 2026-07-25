<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_promo_code_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('module_promo_codes')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('amount');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['promo_code_id', 'item_id'], 'module_promo_code_reward_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_promo_code_rewards');
    }
};
