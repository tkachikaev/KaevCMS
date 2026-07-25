<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemovedModeratorRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_obsolete_moderators_become_editors_and_their_sessions_are_revoked(): void
    {
        $formerModerator = Admin::factory()->editor()->create(['session_version' => 4]);
        $existingEditor = Admin::factory()->editor()->create(['session_version' => 7]);

        DB::table('admins')
            ->where('id', $formerModerator->id)
            ->update(['role' => 'moderator']);

        $migration = require database_path('migrations/2026_07_19_000500_remove_moderator_admin_role.php');
        $migration->up();

        $this->assertDatabaseHas('admins', [
            'id' => $formerModerator->id,
            'role' => AdminRole::Editor->value,
            'session_version' => 5,
        ]);
        $this->assertDatabaseHas('admins', [
            'id' => $existingEditor->id,
            'role' => AdminRole::Editor->value,
            'session_version' => 7,
        ]);
    }
}
