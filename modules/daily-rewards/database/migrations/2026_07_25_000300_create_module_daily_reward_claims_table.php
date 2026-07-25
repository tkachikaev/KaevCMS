<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_daily_reward_claims', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_token')->unique();
            $table->foreignId('calendar_id')->constrained('module_daily_reward_calendars')->restrictOnDelete();
            $table->foreignId('day_id')->constrained('module_daily_reward_days')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_game_account_id')->nullable()->constrained('user_game_accounts')->nullOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->foreignId('reward_inventory_grant_id')->nullable()->constrained('reward_inventory_grants')->nullOnDelete();
            $table->date('reward_date');
            $table->string('user_email', 255);
            $table->string('game_account_login', 45);
            $table->json('items_snapshot');
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(
                ['calendar_id', 'day_id', 'user_game_account_id'],
                'module_daily_reward_account_day_unique',
            );
            $table->index(['game_server_id', 'claimed_at'], 'module_daily_reward_claim_server_date_index');
            $table->index(['user_id', 'claimed_at'], 'module_daily_reward_claim_user_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_daily_reward_claims');
    }
};
