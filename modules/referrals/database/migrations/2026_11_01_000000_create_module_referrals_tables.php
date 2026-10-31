<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_referral_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key', 16)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('invite_ttl_days')->default(7);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('module_referral_settings')->insert([
            'setting_key' => 'global',
            'enabled' => false,
            'invite_ttl_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('module_referral_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('module_referral_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_code_id')->constrained('module_referral_codes')->restrictOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invitee_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('accepted_at');
            $table->timestamp('frozen_at')->nullable();
            $table->string('frozen_reason', 500)->nullable();
            $table->foreignId('frozen_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['inviter_user_id', 'status'], 'ref_invites_inviter_status_idx');
        });

        Schema::create('module_referral_server_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->unique()->constrained('game_servers')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('checks_paused')->default(false);
            $table->boolean('inviter_rewards_enabled')->default(false);
            $table->boolean('invitee_rewards_enabled')->default(false);
            $table->unsignedInteger('max_rewarded_invitees')->nullable();
            $table->timestamps();
        });

        Schema::create('module_referral_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->constrained('game_servers')->cascadeOnDelete();
            $table->string('audience', 16);
            $table->unsignedSmallInteger('level');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('amount');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['game_server_id', 'audience', 'level'], 'ref_rules_server_audience_level_uq');
        });

        // A row exists even if a snapshot contains zero entitlement rows.
        // This prevents a later rule edit from silently rewriting existing promises.
        Schema::create('module_referral_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invite_id')->constrained('module_referral_invites')->restrictOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->timestamp('snapshotted_at');
            $table->timestamps();
            $table->unique(['invite_id', 'game_server_id'], 'ref_snapshots_invite_server_uq');
        });

        Schema::create('module_referral_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('module_referral_snapshots')->restrictOnDelete();
            $table->foreignId('invite_id')->constrained('module_referral_invites')->restrictOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience', 16);
            $table->unsignedSmallInteger('level');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('amount');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reward_inventory_grant_id')->nullable()->unique()
                ->constrained('reward_inventory_grants')->restrictOnDelete();
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();
            $table->unique(['invite_id', 'game_server_id', 'audience', 'level'], 'ref_entitlements_milestone_uq');
            $table->index(['invite_id', 'game_server_id', 'status'], 'ref_entitlements_invite_server_status_idx');
            $table->index(['recipient_user_id', 'status'], 'ref_entitlements_recipient_status_idx');
        });

        Schema::create('module_referral_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invite_id')->constrained('module_referral_invites')->restrictOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->unsignedSmallInteger('max_seen_level')->default(0);
            $table->string('status', 16)->default('pending');
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['invite_id', 'game_server_id'], 'ref_progress_invite_server_uq');
            $table->index(['status', 'next_check_at', 'game_server_id'], 'ref_progress_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_referral_progress');
        Schema::dropIfExists('module_referral_entitlements');
        Schema::dropIfExists('module_referral_snapshots');
        Schema::dropIfExists('module_referral_rules');
        Schema::dropIfExists('module_referral_server_settings');
        Schema::dropIfExists('module_referral_invites');
        Schema::dropIfExists('module_referral_codes');
        Schema::dropIfExists('module_referral_settings');
    }
};
