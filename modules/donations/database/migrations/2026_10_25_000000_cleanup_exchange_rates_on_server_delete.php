<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_donation_game_exchange_rates', function (Blueprint $table): void {
            $table->dropForeign(['game_server_id']);
            $table->foreign('game_server_id')->references('id')->on('game_servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('module_donation_game_exchange_rates', function (Blueprint $table): void {
            $table->dropForeign(['game_server_id']);
            $table->foreign('game_server_id')->references('id')->on('game_servers')->restrictOnDelete();
        });
    }
};
