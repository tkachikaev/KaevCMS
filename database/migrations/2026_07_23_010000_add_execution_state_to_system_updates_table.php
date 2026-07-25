<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_updates', function (Blueprint $table): void {
            $table->string('phase', 32)->nullable()->after('status')->index();
            $table->string('package_sha256', 64)->nullable()->after('package_path');
        });
    }

    public function down(): void
    {
        Schema::table('system_updates', function (Blueprint $table): void {
            $table->dropIndex(['phase']);
            $table->dropColumn(['phase', 'package_sha256']);
        });
    }
};
