<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_promo_code_activations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_token')->unique();
            $table->foreignId('promo_code_id')->constrained('module_promo_codes')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->foreignId('reward_inventory_grant_id')->nullable()->constrained('reward_inventory_grants')->nullOnDelete();
            $table->string('code_snapshot', 64);
            $table->string('user_email', 255);
            $table->timestamp('activated_at');
            $table->timestamps();

            $table->index(['promo_code_id', 'user_id', 'activated_at'], 'module_promo_activations_code_user_index');
            $table->index(['game_server_id', 'activated_at'], 'module_promo_activations_server_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_promo_code_activations');
    }
};
