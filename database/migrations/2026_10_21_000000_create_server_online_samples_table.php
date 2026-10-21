<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Online player counts sampled by the server monitor, one row per server per
        // five-minute bucket. Buckets are aligned across servers so totals can be summed.
        Schema::create('server_online_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->constrained('game_servers')->cascadeOnDelete();
            $table->timestamp('bucket_at');
            $table->unsignedInteger('players');
            $table->unique(['game_server_id', 'bucket_at'], 'server_online_samples_server_bucket_unique');
            $table->index('bucket_at', 'server_online_samples_bucket_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_online_samples');
    }
};
