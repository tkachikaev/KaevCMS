<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_donation_game_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->unique()->constrained('game_servers')->restrictOnDelete();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedInteger('items_per_coin')->nullable();
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('module_donation_game_exchanges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->unsignedBigInteger('coins');
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('items_per_coin');
            $table->unsignedBigInteger('game_amount');
            $table->foreignId('wallet_entry_id')->unique()->constrained('wallet_entries')->restrictOnDelete();
            $table->foreignId('reward_inventory_grant_id')->unique()->constrained('reward_inventory_grants')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('module_donation_game_exchanges')
            && DB::table('module_donation_game_exchanges')->exists()) {
            throw new RuntimeException('Cannot roll back actual coin exchanges.');
        }
        if (Schema::hasTable('module_donation_game_exchange_rates')
            && DB::table('module_donation_game_exchange_rates')->exists()) {
            throw new RuntimeException('Cannot roll back saved exchange settings.');
        }
        Schema::dropIfExists('module_donation_game_exchanges');
        Schema::dropIfExists('module_donation_game_exchange_rates');
    }
};
