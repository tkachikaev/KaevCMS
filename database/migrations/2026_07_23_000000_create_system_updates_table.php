<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_updates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('package_id', 190);
            $table->string('from_version', 64);
            $table->string('target_version', 64);
            $table->string('status', 32)->index();
            $table->string('installation_type', 32);
            $table->string('package_path', 500);
            $table->string('backup_path', 500)->nullable();
            $table->string('log_path', 500)->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('delete_count')->default(0);
            $table->json('manifest');
            $table->string('error_summary', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['target_version', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_updates');
    }
};
