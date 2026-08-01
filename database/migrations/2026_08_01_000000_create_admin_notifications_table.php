<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('severity', 16);
            $table->string('external_key', 191)->nullable();
            $table->string('deduplication_key', 191)->nullable();
            $table->string('title_key', 191);
            $table->string('message_key', 191)->nullable();
            $table->json('parameters')->nullable();
            $table->string('route_name', 191)->nullable();
            $table->json('route_parameters')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('last_occurred_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['admin_id', 'external_key'], 'admin_notifications_admin_external_unique');
            $table->index(
                ['admin_id', 'dismissed_at', 'read_at', 'last_occurred_at'],
                'admin_notifications_inbox_index',
            );
            $table->index(
                ['admin_id', 'deduplication_key', 'resolved_at'],
                'admin_notifications_problem_index',
            );
            $table->index('last_occurred_at', 'admin_notifications_retention_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
