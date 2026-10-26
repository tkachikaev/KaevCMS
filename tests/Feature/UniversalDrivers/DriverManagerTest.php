<?php

namespace Tests\Feature\UniversalDrivers;

use App\Auth\AdminRole;
use App\Livewire\Admin\UniversalDriverManager;
use App\Models\Admin;
use App\Models\LoginServer;
use App\Services\Servers\ServerDriverRegistry;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileFormat;
use App\Support\UniversalDrivers\ProfileValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class DriverManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_sources_can_be_changed_linked_saved_and_reopened(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $component = Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'game', 'rusacis')
            ->set('draft.name', 'Linked filters')
            ->set('draft.config.characters.table', 'players')
            ->set('draft.config.characters.fields.id.column', 'player_id')
            ->call('nextGameStep', 'features')
            ->assertSet('draft.config.filters.0.table', 'players')
            ->assertSee('Только по основному показателю')
            ->set('draft.config.filters.0.table', 'player_access')
            ->assertSet('draft.config.filters.0.link.local_column', 'player_id')
            ->assertSet('draft.config.filters.0.link.foreign_column', '')
            ->assertSee('Ответный столбец в player_access')
            ->call('saveProfile')
            ->assertSet('editing', true)
            ->assertSet('error', __('drivers.filter_link_required'));
        $this->assertNull(app(DriverProfileCatalog::class)->find('game', 'linked-filters'));
        $component->set('draft.config.filters.0.link.foreign_column', 'char_id')
            ->call('saveProfile')->assertHasNoErrors()->assertSet('editing', false);
        $saved = app(DriverProfileCatalog::class)->find('game', 'linked-filters')['profile'];
        $this->assertSame(['local_column' => 'player_id', 'foreign_column' => 'char_id'], $saved['config']['filters'][0]['link']);
        $this->assertSame('player_access', $saved['config']['filters'][0]['table']);
        $component->call('editProfile', 'game', 'linked-filters')
            ->assertSet('draft.config.filters.0.link.foreign_column', 'char_id')
            ->set('draft.config.filters.0.table', 'players')
            ->assertSet('draft.config.filters.0', ['table' => 'players', 'column' => 'accesslevel', 'op' => 'eq', 'value' => 0]);
    }

    public function test_game_editor_saves_renamed_tables_and_infers_capabilities_without_checkboxes(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'game', 'rusacis')
            ->set('draft.id', 'my-game')
            ->set('draft.name', 'My game')
            ->set('draft.config.characters.table', 'players')
            ->set('draft.config.characters.fields.id.column', 'player_id')
            ->set('draft.config.characters.fields.level.column', 'player_level')
            ->set('enabled', [])
            ->call('nextGameStep', 'features')
            ->assertSet('draft.config.relations.heroes.local_column', 'player_id')
            ->call('saveProfile')->assertHasNoErrors()->assertSet('editing', false);
        $profile = app(DriverProfileCatalog::class)->find('game', 'my-game')['profile'];
        $this->assertContains('level', $profile['config']['capabilities']);
        $this->assertSame('player_level', $profile['config']['rankings']['level']['column']);
        $this->assertSame('players', $profile['config']['relations']['leader']['table']);
        $this->assertSame('player_id', $profile['config']['relations']['leader']['foreign_column']);
        $this->assertNotContains('characters', array_column($profile['config']['requirements'], 'table'));
    }

    public function test_registration_rows_can_be_added_edited_saved_and_removed(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login')
            ->set('draft.name', 'Registration rows')
            ->call('addRegistrationRow')
            ->assertSet('registrationRows', [['column' => '', 'value' => '0']])
            ->set('registrationRows.0.column', 'custom_flag')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->assertSet('editing', false);
        $catalog = app(DriverProfileCatalog::class);
        $profile = $catalog->find('login', 'registration-rows')['profile'];
        $this->assertSame(['custom_flag' => '0'], $profile['config']['accounts']['create']['fixed_values']);
        $this->assertContains('custom_flag', $profile['config']['requirements'][0]['columns']);

        Livewire::test(UniversalDriverManager::class)
            ->call('editProfile', 'login', 'registration-rows')
            ->assertSet('registrationRows', [['column' => 'custom_flag', 'value' => '0']])
            ->set('registrationRows.0.column', 'another_flag')
            ->set('registrationRows.0.value', '1')
            ->call('saveProfile')->assertSet('editing', false);
        $this->assertSame(['another_flag' => '1'], $catalog->find('login', 'registration-rows')['profile']['config']['accounts']['create']['fixed_values']);
        Livewire::test(UniversalDriverManager::class)
            ->call('editProfile', 'login', 'registration-rows')
            ->call('removeRegistrationRow', 0)
            ->call('saveProfile')->assertSet('editing', false);
        $this->assertArrayNotHasKey('fixed_values', $catalog->find('login', 'registration-rows')['profile']['config']['accounts']['create']);
    }

    public function test_login_editor_preserves_selected_functionality_and_checks_renamed_fields(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login', 'rusacis')
            ->set('draft.name', 'Моя сборка')
            ->set('draft.id', 'custom-login')
            ->set('draft.config.accounts.table', 'player_accounts')
            ->set('draft.config.accounts.fields.login', 'username')
            ->set('draft.config.accounts.fields.password', 'secret_hash')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->assertSet('editing', false);

        $profile = app(DriverProfileCatalog::class)->find('login', 'custom-login')['profile'];
        $this->assertSame(ProfileFormat::LOGIN_CAPABILITIES, $profile['config']['capabilities']);
        $this->assertSame('username', $profile['config']['accounts']['ownership']['column']);
        $primary = collect($profile['config']['requirements'])->firstWhere('table', 'player_accounts');
        $this->assertTrue($primary['required']);
        $this->assertContains('username', $primary['columns']);
        $this->assertContains('secret_hash', $primary['columns']);
        $this->assertNotContains('accounts', array_column($profile['config']['requirements'], 'table'));
        app(ProfileValidator::class)->validate($profile);
    }

    public function test_login_password_mapping_is_required_and_validation_is_localized(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login')
            ->set('draft.name', 'Test Login')
            ->set('draft.config.accounts.fields.password', '')
            ->call('saveProfile')
            ->assertHasErrors(['draft.config.accounts.fields.password' => 'required'])
            ->assertSet('editing', true);
        $this->assertDatabaseCount('universal_driver_profiles', 0);
    }

    public function test_login_editor_can_save_and_copy_a_read_only_profile_without_enabling_writes(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login', 'rusacis')
            ->set('draft.id', 'read-only-login')
            ->set('draft.name', 'Read only')
            ->set('enabled', ['account_lookup' => true])
            ->set('draft.config.accounts.fields.password', null)
            ->call('saveProfile')->assertHasNoErrors()->assertSet('editing', false);
        $profile = app(DriverProfileCatalog::class)->find('login', 'read-only-login')['profile'];
        $this->assertSame(['account_lookup'], $profile['config']['capabilities']);
        $this->assertNotContains('password', $profile['config']['requirements'][0]['columns']);
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login', 'read-only-login')
            ->assertSet('enabled.account_creation', false)
            ->assertSet('enabled.password_change', false)
            ->set('draft.id', 'read-only-copy')
            ->call('saveProfile')->assertHasNoErrors();
        $this->assertSame(['account_lookup'], app(DriverProfileCatalog::class)->find('login', 'read-only-copy')['profile']['config']['capabilities']);
    }

    public function test_login_editor_rejects_an_empty_capability_selection_without_saving(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login', 'rusacis')
            ->set('draft.id', 'empty-functions')
            ->set('enabled', [])
            ->call('saveProfile')->assertSet('editing', true);
        $this->assertNull(app(DriverProfileCatalog::class)->find('login', 'empty-functions'));
    }

    public function test_starting_from_a_template_does_not_save_or_change_the_original(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $catalog = app(DriverProfileCatalog::class);
        $original = $catalog->find('game', 'rusacis')['profile'];
        $displayConfig = $original['config'];
        foreach ($displayConfig['filters'] as &$filter) {
            $filter['table'] = $displayConfig['characters']['table'];
        }
        unset($filter);

        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'game', 'rusacis')
            ->assertSet('originalId', null)
            ->assertSet('draft.id', '')
            ->assertSet('draft.config', $displayConfig)
            ->assertSet('enabled.characters', true)
            ->assertSet('editing', true);

        $this->assertDatabaseCount('universal_driver_profiles', 0);
        $this->assertSame($original, $catalog->find('game', 'rusacis')['profile']);
    }

    public function test_a_table_typed_into_a_field_gets_its_own_relation_and_unused_ones_are_dropped(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $component = Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'game', 'rusacis')
            ->set('draft.id', 'typed-table')
            ->set('draft.name', 'Typed table')
            ->call('changeFieldKind', 'class_id', 'related')
            ->assertSet('draft.config.characters.fields.class_id.relation', 'f_class_id')
            ->assertSet('draft.config.relations.f_class_id.table', '')
            ->set('draft.config.relations.f_class_id.table', 'player_stats')
            ->set('draft.config.relations.f_class_id.local_column', 'obj_Id')
            ->set('draft.config.relations.f_class_id.foreign_column', 'char_id')
            ->set('draft.config.characters.fields.class_id.column', 'cid');

        // Going back to a plain column removes the relation nobody uses any more.
        $component->call('changeFieldKind', 'class_id', 'column');
        $this->assertArrayNotHasKey('f_class_id', $component->get('draft.config.relations'));

        // A typed table that stays in use is saved with the profile.
        $component->call('changeFieldKind', 'class_id', 'related')
            ->set('draft.config.relations.f_class_id.table', 'player_stats')
            ->set('draft.config.relations.f_class_id.local_column', 'obj_Id')
            ->set('draft.config.relations.f_class_id.foreign_column', 'char_id')
            ->set('draft.config.characters.fields.class_id.column', 'cid')
            ->call('saveProfile')->assertHasNoErrors()->assertSet('editing', false);

        $profile = app(DriverProfileCatalog::class)->find('game', 'typed-table')['profile'];
        $this->assertSame('f_class_id', $profile['config']['characters']['fields']['class_id']['relation']);
        $this->assertSame('player_stats', $profile['config']['relations']['f_class_id']['table']);
    }

    public function test_typing_a_table_in_a_field_row_switches_between_the_main_table_and_another_one(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $component = Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'game')
            ->assertSet('fieldTables.race', 'characters')
            ->set('draft.config.characters.fields.race.column', 'race')
            ->set('fieldTables.race', 'race_data')
            ->assertSet('draft.config.characters.fields.race.kind', 'related')
            ->assertSet('draft.config.characters.fields.race.relation', 'f_race')
            ->assertSet('draft.config.relations.f_race.table', 'race_data')
            ->assertSet('draft.config.characters.fields.race.column', 'race');

        // Typing the main table back makes it a plain column again and drops the unused relation.
        $component->set('fieldTables.race', 'characters')
            ->assertSet('draft.config.characters.fields.race.kind', 'column');
        $this->assertArrayNotHasKey('f_race', $component->get('draft.config.relations'));

        // Renaming the main table moves every plain-column row with it.
        $component->set('draft.config.characters.table', 'players')
            ->assertSet('fieldTables.race', 'players')
            ->assertSet('fieldTables.level', 'players');
    }

    public function test_required_fields_are_not_given_a_table_of_their_own(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'game')
            ->set('fieldTables.id', 'other_table')
            ->assertSet('draft.config.characters.fields.id.kind', 'column')
            ->assertSet('draft.config.relations', []);
    }

    public function test_new_driver_can_be_saved_without_knowing_its_technical_identifier(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');

        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'login')
            ->set('draft.name', 'My Login')
            ->call('saveProfile')
            ->assertSet('editing', false);

        $this->assertDatabaseHas('universal_driver_profiles', ['profile_id' => 'my-login']);
    }

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
        // Hiding a bundled profile must hide its legacy identifier in the
        // new connection form too, not merely in the Drivers catalog.
        $this->assertNotContains('rusacis', app(ServerDriverRegistry::class)->loginDriverKeys());
        Livewire::test(UniversalDriverManager::class)->call('restoreProfile', 'login', 'rusacis');
        $this->assertSame($original, $catalog->find('login', 'rusacis')['profile']);
        $this->assertContains('rusacis', app(ServerDriverRegistry::class)->loginDriverKeys());
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
