<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Player notifications shared by the account area and modules (the bell).
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 80);
            // Unread notifications with the same key are merged ("3 new replies").
            $table->string('group_key', 191)->nullable();
            $table->string('title_key', 191);
            $table->text('parameters')->nullable();
            $table->string('url', 500);
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('occurred_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'occurred_at']);
            $table->index(['user_id', 'group_key', 'read_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
