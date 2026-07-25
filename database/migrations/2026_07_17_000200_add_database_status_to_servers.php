<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_servers', function (Blueprint $table): void {
            $table->string('database_status', 24)->default('unknown')->index();
            $table->string('database_error', 64)->nullable();
            $table->timestamp('database_checked_at')->nullable();
        });

        Schema::table('game_servers', function (Blueprint $table): void {
            $table->string('database_status', 24)->default('unknown')->index();
            $table->string('database_error', 64)->nullable();
            $table->timestamp('database_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropIndex(['database_status']);
            $table->dropColumn(['database_status', 'database_error', 'database_checked_at']);
        });

        Schema::table('login_servers', function (Blueprint $table): void {
            $table->dropIndex(['database_status']);
            $table->dropColumn(['database_status', 'database_error', 'database_checked_at']);
        });
    }
};
