<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_module_migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('module_id', 100)->index();
            $table->string('migration', 190);
            $table->char('checksum', 64);
            $table->unsignedInteger('batch');
            $table->timestamp('ran_at');
            $table->unique(['module_id', 'migration']);
        });

        Schema::table('cms_modules', function (Blueprint $table): void {
            $table->string('migration_error', 190)->nullable()->after('last_error_at');
            $table->timestamp('migration_error_at')->nullable()->after('migration_error');
        });
    }

    public function down(): void
    {
        Schema::table('cms_modules', function (Blueprint $table): void {
            $table->dropColumn(['migration_error', 'migration_error_at']);
        });

        Schema::dropIfExists('cms_module_migrations');
    }
};
