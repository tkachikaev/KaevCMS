<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_server_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->constrained('game_servers')->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['game_server_id', 'feature_key'], 'game_server_feature_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_server_features');
    }
};
