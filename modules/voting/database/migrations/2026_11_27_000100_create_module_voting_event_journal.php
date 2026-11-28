<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_voting_settings', function (Blueprint $table): void {
            $table->string('event_cursor', 19)->default('0');
            $table->timestamp('sync_retry_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_result', 32)->nullable();
        });

        Schema::create('module_voting_votes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->uuid('vote_id');
            $table->string('player_ref', 128);
            $table->foreignId('binding_id')->nullable()->constrained('module_voting_bindings')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_game_account_id')->nullable()->constrained('user_game_accounts')->nullOnDelete();
            $table->foreignId('game_server_id')->nullable()->constrained('game_servers')->nullOnDelete();
            $table->string('game_account_login', 190)->nullable();
            $table->string('status', 16);
            $table->unsignedBigInteger('status_version');
            $table->dateTime('voted_at', 6);
            $table->dateTime('status_changed_at', 6);
            $table->json('items_snapshot');
            $table->string('reward_state', 24)->default('waiting');
            $table->string('reward_reason', 64)->nullable();
            $table->foreignId('reward_inventory_grant_id')->nullable()->unique()->constrained('reward_inventory_grants')->restrictOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'vote_id'], 'voting_vote_identity_uq');
            $table->index(['status', 'id'], 'voting_votes_status_idx');
            $table->index(['user_id', 'id'], 'voting_votes_user_idx');
        });

        Schema::create('module_voting_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->uuid('event_id');
            $table->uuid('vote_id');
            $table->string('cursor', 19);
            $table->string('player_ref', 128);
            $table->string('status', 16);
            $table->unsignedBigInteger('status_version');
            $table->dateTime('voted_at', 6);
            $table->dateTime('status_changed_at', 6);
            $table->timestamp('processed_at');
            $table->unique(['project_id', 'event_id'], 'voting_event_identity_uq');
            $table->unique(['project_id', 'cursor'], 'voting_event_cursor_uq');
            $table->unique(['project_id', 'vote_id', 'status_version'], 'voting_event_version_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_voting_events');
        Schema::dropIfExists('module_voting_votes');
        Schema::table('module_voting_settings', function (Blueprint $table): void {
            $table->dropColumn(['event_cursor', 'sync_retry_at', 'last_sync_at', 'last_sync_result']);
        });
    }
};
