<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 64)->index();
            $table->string('action', 100)->index();
            $table->string('actor_type', 40)->nullable()->index();
            $table->string('actor_id', 100)->nullable();
            $table->string('actor_name', 190)->nullable();
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 100)->nullable();
            $table->string('target_name', 255)->nullable();
            $table->string('result', 20)->default('success')->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['category', 'created_at']);
            $table->index(['actor_type', 'actor_id', 'created_at']);
            $table->index(['target_type', 'target_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
