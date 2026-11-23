<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-calendar claim limit. Existing calendars keep the per-game-account rule.
 *
 * The site-user limit is enforced by a separate nullable column: only claims made in that
 * mode store the user id there, so historical per-account claims (one user, several
 * accounts, same day) keep NULL and never collide with the new unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_daily_reward_calendars', function (Blueprint $table): void {
            $table->string('claim_limit', 16)->default('game_account')->after('enabled');
        });

        Schema::table('module_daily_reward_claims', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_user_limit_id')->nullable()->after('user_game_account_id');
            $table->unique(
                ['calendar_id', 'day_id', 'site_user_limit_id'],
                'module_daily_reward_user_day_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('module_daily_reward_claims', function (Blueprint $table): void {
            $table->dropUnique('module_daily_reward_user_day_unique');
            $table->dropColumn('site_user_limit_id');
        });

        Schema::table('module_daily_reward_calendars', function (Blueprint $table): void {
            $table->dropColumn('claim_limit');
        });
    }
};
