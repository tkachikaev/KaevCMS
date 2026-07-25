<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_game_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('login_server_id')->constrained('login_servers')->restrictOnDelete();
            $table->foreignId('registration_game_server_id')->nullable()->constrained('game_servers')->nullOnDelete();
            $table->string('game_login', 45);
            $table->string('normalized_login', 45);
            $table->boolean('created_via_cms')->default(true);
            $table->timestamps();

            $table->unique(['login_server_id', 'normalized_login'], 'user_game_accounts_server_login_unique');
            $table->index(['user_id', 'login_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_game_accounts');
    }
};
