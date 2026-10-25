<?php

namespace Tests\Feature\UniversalDrivers;

use App\Auth\AdminRole;
use App\Livewire\Admin\UniversalDriverManager;
use App\Models\Admin;
use App\Models\LoginServer;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\ProfileEditorDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class DriverManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_route_and_role_permissions_are_enforced(): void
    {
        $url = route('admin.settings.drivers');
        $this->get($url)->assertRedirect(route('admin.login'));
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Editor]), 'admin')
            ->get($url)->assertForbidden();
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Auditor]), 'admin')
            ->get($url)->assertOk()->assertSee('RUSaCis')->assertDontSee('Создать драйвер');

        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login')->assertForbidden();
        $this->assertDatabaseCount('universal_driver_profiles', 0);
    }

    public function test_owner_can_create_edit_export_and_remove_profile_without_game_database(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $profile = app(ProfileEditorDraft::class)->blank('login');
        $profile['id'] = 'my-login';
        $profile['name'] = 'My Login';
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login')
            ->set('draft', $profile)
            ->call('saveProfile')
            ->assertSet('editing', false);

        $catalog = app(DriverProfileCatalog::class);
        $this->assertSame('My Login', $catalog->find('login', 'my-login')['profile']['name']);
        Livewire::test(UniversalDriverManager::class)
            ->call('editProfile', 'login', 'my-login')
            ->set('draft.name', 'Revised Login')
            ->call('saveProfile');
        $this->assertSame('Revised Login', $catalog->find('login', 'my-login')['profile']['name']);
        Livewire::test(UniversalDriverManager::class)
            ->call('exportProfile', 'login', 'my-login')
            ->assertFileDownloaded('kaevcms-login-my-login.json');
        Livewire::test(UniversalDriverManager::class)
            ->call('askDelete', 'login', 'my-login')
            ->call('deleteProfile');
        $this->assertNull($catalog->find('login', 'my-login'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'universal_driver_save']);
    }

    public function test_edit_builtin_profile_preserves_release_copy_and_hidden_profiles_can_be_restored(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $catalog = app(DriverProfileCatalog::class);
        $original = $catalog->find('login', 'rusacis')['profile'];
        Livewire::test(UniversalDriverManager::class)
            ->call('editProfile', 'login', 'rusacis')
            ->set('draft.name', 'Edited RUSaCis')
            ->call('saveProfile');
        $this->assertSame('Edited RUSaCis', $catalog->find('login', 'rusacis')['profile']['name']);
        Livewire::test(UniversalDriverManager::class)
            ->call('askDelete', 'login', 'rusacis')->call('deleteProfile');
        $this->assertNull($catalog->find('login', 'rusacis'));
        $this->assertDatabaseHas('universal_driver_profiles', ['profile_id' => 'rusacis', 'is_deleted' => 1]);
        Livewire::test(UniversalDriverManager::class)->call('restoreProfile', 'login', 'rusacis');
        $this->assertSame($original, $catalog->find('login', 'rusacis')['profile']);
    }

    public function test_used_driver_cannot_be_removed_through_administration(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        LoginServer::factory()->create(['driver' => 'rusacis']);
        Livewire::test(UniversalDriverManager::class)
            ->call('askDelete', 'login', 'rusacis')->call('deleteProfile')
            ->assertSet('error', __('drivers.cannot_delete'));
        $this->assertNotNull(app(DriverProfileCatalog::class)->find('login', 'rusacis'));
    }

    public function test_import_rejects_invalid_json_and_does_not_overwrite_existing_profile(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $bad = UploadedFile::fake()->createWithContent('bad.json', '{"name":"Bad","type":"login","config":{"sql":"DROP TABLE accounts"}}');
        Livewire::test(UniversalDriverManager::class)
            ->set('importFile', $bad)->call('importProfile')
            ->assertSet('notice', '');
        $this->assertDatabaseCount('universal_driver_profiles', 0);

        $original = app(DriverProfileCatalog::class)->export('login', 'rusacis');
        $good = UploadedFile::fake()->createWithContent('valid.json', $original);
        Livewire::test(UniversalDriverManager::class)
            ->set('importFile', $good)->call('importProfile')
            ->assertSet('tab', 'login');
        $this->assertNotNull(app(DriverProfileCatalog::class)->find('login', 'rusacis-2'));
        $this->assertSame('RUSaCis LoginServer', app(DriverProfileCatalog::class)->find('login', 'rusacis')['profile']['name']);
    }

    public function test_malformed_mapping_fails_closed_and_keeps_previous_saved_profile(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $catalog = app(DriverProfileCatalog::class);
        $name = $catalog->find('game', 'rusacis')['profile']['name'];
        Livewire::test(UniversalDriverManager::class)
            ->call('editProfile', 'game', 'rusacis')
            ->set('draft.config.characters.fields.id.column', 'id;drop')
            ->call('saveProfile')
            ->assertSet('editing', true);
        $this->assertSame($name, $catalog->find('game', 'rusacis')['profile']['name']);
        $this->assertSame(0, DB::table('universal_driver_profiles')->count());
    }
}
