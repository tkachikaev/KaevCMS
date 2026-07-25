<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_inventory_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('grant_key', 190)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->string('source_type', 64);
            $table->string('source_reference', 190)->nullable();
            $table->string('source_label', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->index(['user_id', 'game_server_id', 'granted_at'], 'reward_grants_user_server_date_index');
            $table->index(['source_type', 'source_reference'], 'reward_grants_source_index');
        });

        Schema::create('reward_inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reward_inventory_grant_id')->constrained('reward_inventory_grants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->string('item_name', 190)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('status', 32)->default('available');
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'game_server_id', 'status'], 'reward_items_user_server_status_index');
            $table->index(['game_server_id', 'item_id'], 'reward_items_server_item_index');
        });

        Schema::create('reward_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->uuid('request_token')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->foreignId('user_game_account_id')->nullable()->constrained('user_game_accounts')->nullOnDelete();
            $table->unsignedBigInteger('character_id');
            $table->string('character_name', 190);
            $table->string('account_login', 45);
            $table->string('status', 32)->default('pending');
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'game_server_id', 'requested_at'], 'reward_deliveries_user_server_date_index');
            $table->index(['status', 'requested_at'], 'reward_deliveries_status_date_index');
        });

        Schema::create('reward_delivery_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reward_delivery_id')->constrained('reward_deliveries')->cascadeOnDelete();
            $table->foreignId('reward_inventory_item_id')->constrained('reward_inventory_items')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->string('item_name', 190)->nullable();
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->unique(['reward_delivery_id', 'reward_inventory_item_id'], 'reward_delivery_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_delivery_items');
        Schema::dropIfExists('reward_deliveries');
        Schema::dropIfExists('reward_inventory_items');
        Schema::dropIfExists('reward_inventory_grants');
    }
};
