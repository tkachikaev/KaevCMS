<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_updates', function (Blueprint $table): void {
            $table->string('execution_mode', 16)->default('web')->after('installation_type');
            $table->text('maintenance_secret')->nullable()->after('package_sha256');
            $table->timestamp('agent_requested_at')->nullable()->after('started_at')->index();
            $table->timestamp('agent_seen_at')->nullable()->after('agent_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('system_updates', function (Blueprint $table): void {
            $table->dropIndex(['agent_requested_at']);
            $table->dropColumn([
                'execution_mode',
                'maintenance_secret',
                'agent_requested_at',
                'agent_seen_at',
            ]);
        });
    }
};
