<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('total_limit')->default(0);
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->unsignedBigInteger('activations_count')->default(0);
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['game_server_id', 'enabled'], 'module_promo_codes_server_enabled_index');
            $table->index(['enabled', 'starts_at', 'ends_at'], 'module_promo_codes_availability_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_promo_codes');
    }
};
