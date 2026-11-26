<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_voting_settings', function (Blueprint $table): void {
            $table->boolean('player_access_enabled')->default(false);
            $table->timestamp('intent_retry_at')->nullable();
        });

        Schema::create('module_voting_server_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->unique()->constrained('game_servers')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('items');
            $table->timestamps();
        });

        Schema::create('module_voting_bindings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            // Preserve the opaque reference if its recipient is removed; never assign it to a new account.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_game_account_id')->nullable()->constrained('user_game_accounts')->nullOnDelete();
            $table->foreignId('game_server_id')->nullable()->constrained('game_servers')->nullOnDelete();
            $table->string('player_ref', 64)->unique();
            $table->uuid('request_id')->nullable()->unique();
            $table->uuid('intent_id')->nullable();
            $table->unsignedBigInteger('credential_revision')->default(0);
            $table->timestamp('attempt_expires_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'user_id', 'user_game_account_id', 'game_server_id'], 'voting_recipient_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_voting_bindings');
        Schema::dropIfExists('module_voting_server_rewards');
        Schema::table('module_voting_settings', function (Blueprint $table): void {
            $table->dropColumn(['player_access_enabled', 'intent_retry_at']);
        });
    }
};
