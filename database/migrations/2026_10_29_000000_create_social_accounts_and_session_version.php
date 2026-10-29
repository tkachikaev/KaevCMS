<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_set_at')->nullable();
            $table->unsignedBigInteger('auth_session_version')->default(1);
        });

        // Every account created before this migration had a user-selected password.
        DB::table('users')->whereNull('password_set_at')
            ->update(['password_set_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);

        Schema::create('user_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // 16 + 160 chars stay below MySQL 5.5 InnoDB's 767-byte utf8mb4 index limit.
            $table->string('provider', 16);
            $table->string('provider_user_id', 160);
            $table->timestamp('linked_at')->useCurrent();
            $table->unique(['provider', 'provider_user_id'], 'social_provider_identity_unique');
            $table->unique(['user_id', 'provider'], 'social_user_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_accounts');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['password_set_at', 'auth_session_version']);
        });
    }
};
