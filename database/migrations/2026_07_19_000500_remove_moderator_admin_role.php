<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admins')
            ->where('role', 'moderator')
            ->update([
                'role' => 'editor',
                'session_version' => DB::raw('session_version + 1'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Converted accounts stay editors because their previous role cannot be restored safely.
    }
};
