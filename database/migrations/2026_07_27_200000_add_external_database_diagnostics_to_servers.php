<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['login_servers', 'game_servers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('database_last_success_at')->nullable()->after('database_checked_at');
                $table->string('database_last_error_class', 191)->nullable()->after('database_last_success_at');
                $table->timestamp('database_last_error_at')->nullable()->after('database_last_error_class');
                $table->unsignedInteger('database_latency_ms')->nullable()->after('database_last_error_at');
                $table->string('database_schema_profile', 64)->nullable()->after('database_latency_ms');
                $table->json('database_capabilities')->nullable()->after('database_schema_profile');
                $table->json('database_table_checks')->nullable()->after('database_capabilities');
            });
        }
    }

    public function down(): void
    {
        foreach (['game_servers', 'login_servers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn([
                    'database_last_success_at',
                    'database_last_error_class',
                    'database_last_error_at',
                    'database_latency_ms',
                    'database_schema_profile',
                    'database_capabilities',
                    'database_table_checks',
                ]);
            });
        }
    }
};
