<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SplitReadOnlyAdminRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_read_only_and_demo_accounts_become_auditors_and_sessions_are_revoked(): void
    {
        $legacyReadOnly = Admin::factory()->auditor()->create(['session_version' => 4]);
        $legacyDemoViewer = Admin::factory()->auditor()->create(['session_version' => 4]);
        $auditor = Admin::factory()->auditor()->create(['session_version' => 7]);

        DB::table('admins')
            ->where('id', $legacyReadOnly->id)
            ->update(['role' => 'read_only']);

        DB::table('admins')
            ->where('id', $legacyDemoViewer->id)
            ->update(['role' => 'demo_viewer']);

        $splitMigration = require database_path('migrations/2026_07_29_000000_split_read_only_admin_role.php');
        $splitMigration->up();

        $mergeMigration = require database_path('migrations/2026_07_29_010000_merge_demo_viewer_into_auditor.php');
        $mergeMigration->up();

        $this->assertDatabaseHas('admins', [
            'id' => $legacyReadOnly->id,
            'role' => AdminRole::Auditor->value,
            'session_version' => 6,
        ]);
        $this->assertDatabaseHas('admins', [
            'id' => $legacyDemoViewer->id,
            'role' => AdminRole::Auditor->value,
            'session_version' => 5,
        ]);
        $this->assertDatabaseHas('admins', [
            'id' => $auditor->id,
            'role' => AdminRole::Auditor->value,
            'session_version' => 7,
        ]);
    }
}
