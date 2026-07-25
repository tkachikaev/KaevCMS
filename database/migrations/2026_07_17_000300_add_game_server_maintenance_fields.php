<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->boolean('maintenance_enabled')->default(false)->index();
            $table->timestamp('maintenance_until')->nullable();
        });

        Schema::table('game_server_translations', function (Blueprint $table): void {
            $table->string('maintenance_message', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_server_translations', function (Blueprint $table): void {
            $table->dropColumn('maintenance_message');
        });

        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropIndex(['maintenance_enabled']);
            $table->dropColumn(['maintenance_enabled', 'maintenance_until']);
        });
    }
};
