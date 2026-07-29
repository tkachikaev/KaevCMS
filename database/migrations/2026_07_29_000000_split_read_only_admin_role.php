<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admins')
            ->where('role', 'read_only')
            ->update([
                'role' => 'demo_viewer',
                'session_version' => DB::raw('session_version + 1'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('admins')
            ->whereIn('role', ['auditor', 'demo_viewer'])
            ->update([
                'role' => 'read_only',
                'session_version' => DB::raw('session_version + 1'),
                'updated_at' => now(),
            ]);
    }
};
