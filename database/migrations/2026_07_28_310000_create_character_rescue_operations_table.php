<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_rescue_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_server_id')->nullable()->constrained('game_servers')->nullOnDelete();
            $table->foreignId('user_game_account_id')->nullable()->constrained('user_game_accounts')->nullOnDelete();
            $table->unsignedBigInteger('character_id');
            $table->string('character_name', 190);
            $table->string('account_login', 45);
            $table->string('location_name', 100);
            $table->integer('old_x')->nullable();
            $table->integer('old_y')->nullable();
            $table->integer('old_z')->nullable();
            $table->integer('target_x');
            $table->integer('target_y');
            $table->integer('target_z');
            $table->string('status', 32)->default('pending');
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'game_server_id', 'character_id', 'status', 'completed_at'],
                'character_rescue_cooldown_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_rescue_operations');
    }
};
