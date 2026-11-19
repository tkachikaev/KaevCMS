<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use App\Support\Modules\ModuleValidator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ForumModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_installation_registers_forum_without_hijacking_standard_module_routes(): void
    {
        $module = app(ModuleManager::class)->inspect('forum');
        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible']);
        $this->assertSame('enabled', $module['status']);
        $this->assertSame('forum', $module['web_route_prefix']);
        $this->assertSame([], $module['pending_migrations']);

        $this->assertTrue(Route::has('modules.forum.index'));
        $this->assertSame('/forum', route('modules.forum.index', absolute: false));
        $this->assertTrue(Route::has('admin.module-pages.forum.settings'));
        $this->assertContains('modules.forum.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.forum.settings'));
        $this->get('/modules/forum')->assertNotFound();

        $this->get('/forum')
            ->assertOk()
            ->assertSee(__('module-forum::messages.empty_title'))
            ->assertDontSee('data-forum-owner-settings', false)
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_owner_is_recognized_from_valid_admin_session_only(): void
    {
        $owner = Admin::factory()->create(['name' => 'ForumChief']);

        $this->actingAs($owner, 'admin')
            ->get('/forum')
            ->assertOk()
            ->assertSee('ForumChief')
            ->assertSee('data-forum-owner-settings', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/forum/settings')
            ->assertOk()
            ->assertSee(__('module-forum::messages.settings_pending'));
    }

    public function test_administrator_and_editor_cannot_manage_forum(): void
    {
        $administrator = Admin::factory()->administrator()->create(['name' => 'ForumAdmin']);
        $this->actingAs($administrator, 'admin')
            ->get('/forum')
            ->assertOk()
            ->assertSee('ForumAdmin')
            ->assertDontSee('data-forum-owner-settings', false);
        $this->get('/admin/extensions/forum/settings')->assertForbidden();

        $editor = Admin::factory()->editor()->create();
        $this->actingAs($editor, 'admin')
            ->get('/forum')
            ->assertOk()
            ->assertDontSee('data-forum-owner-settings', false);
        $this->get('/admin/extensions/forum/settings')->assertForbidden();
    }

    public function test_player_public_name_and_fake_admin_flag_do_not_grant_owner_rights(): void
    {
        $player = User::factory()->create([
            'name' => 'private-login-name',
            'display_name' => 'PublicNickname',
            'is_admin' => true,
        ]);

        $this->actingAs($player, 'web')
            ->get('/forum')
            ->assertOk()
            ->assertSee('PublicNickname')
            ->assertDontSee('private-login-name')
            ->assertDontSee('data-forum-owner-settings', false);
        $this->post('/forum/author', ['mode' => 'admin'])->assertForbidden();
    }

    public function test_revoked_or_inactive_admin_session_cannot_impersonate_owner(): void
    {
        $owner = Admin::factory()->create(['name' => 'RevokedOwner']);
        $this->actingAs($owner, 'admin');
        $this->withSession(['admin_session_version' => (int) $owner->session_version + 1]);

        $this->get('/forum')
            ->assertOk()
            ->assertDontSee('data-forum-owner-settings', false);
        $this->post('/forum/author', ['mode' => 'admin'])->assertForbidden();

        $this->withSession(['admin_session_version' => (int) $owner->session_version]);
        $owner->forceFill(['is_active' => false])->save();

        $this->get('/forum')
            ->assertOk()
            ->assertDontSee('data-forum-owner-settings', false);
    }

    public function test_two_simultaneous_guards_keep_explicit_author_selection(): void
    {
        $owner = Admin::factory()->create(['name' => 'SiteOwner']);
        $player = User::factory()->create(['name' => 'InternalUser', 'display_name' => 'ForumPlayer']);

        $this->actingAs($owner, 'admin');
        $this->actingAs($player, 'web');

        $this->get('/forum')->assertOk()->assertSee('SiteOwner')->assertSee('data-forum-owner-settings', false);
        $this->post('/forum/author', ['mode' => 'user'])->assertRedirect(route('modules.forum.index'));
        $this->get('/forum')
            ->assertOk()
            ->assertSee('ForumPlayer')
            ->assertDontSee('data-forum-owner-settings', false);

        $this->post('/forum/author', ['mode' => 'admin'])->assertRedirect(route('modules.forum.index'));
        $this->get('/forum')->assertOk()->assertSee('data-forum-owner-settings', false);

        $this->withSession(['forum.author_mode' => 'user']);
        $this->post('/forum/author', ['mode' => 'unknown'])->assertSessionHasErrors('mode');
    }

    public function test_expired_selected_admin_identity_does_not_silently_fall_back_to_player(): void
    {
        $owner = Admin::factory()->create();
        $player = User::factory()->create();

        $this->actingAs($owner, 'admin');
        $this->actingAs($player, 'web');
        $this->withSession(['forum.author_mode' => 'admin', 'admin_session_version' => $owner->session_version + 2]);

        $this->get('/forum')
            ->assertOk()
            ->assertDontSee('data-forum-owner-settings', false);
        $this->post('/forum/author', ['mode' => 'admin'])->assertForbidden();
        $this->post('/forum/author', ['mode' => 'user'])->assertRedirect();
        $this->get('/forum')->assertSee($player->publicName());
    }

    public function test_disabling_forum_closes_public_and_admin_routes(): void
    {
        $this->get('/forum')->assertOk();
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin')->get('/admin/extensions/forum/settings')->assertOk();

        app(ModuleManager::class)->disable('forum');
        $this->get('/forum')->assertNotFound();
        $this->post('/forum/author', ['mode' => 'admin'])->assertNotFound();
        $this->get('/admin/extensions/forum/settings')->assertNotFound();
    }

    public function test_arbitrary_modules_cannot_claim_top_level_routes(): void
    {
        $id = 'forum-prefix-fixture';
        $root = base_path('modules/'.$id);
        $files = new Filesystem;

        $files->ensureDirectoryExists($root.'/routes');
        try {
            $manifest = json_decode($files->get(base_path('modules/forum/module.json')), true, flags: JSON_THROW_ON_ERROR);
            $manifest['id'] = $id;
            unset($manifest['namespace'], $manifest['autoload'], $manifest['bootstrap'], $manifest['views'], $manifest['lang']);
            $manifest['routes'] = ['web' => 'routes/web.php', 'web_prefix' => 'forum'];

            $files->put($root.'/routes/web.php', "<?php\n");
            $files->put($root.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));

            $result = app(ModuleValidator::class)->inspect($id, base_path('modules'));
            $this->assertFalse($result['valid']);
            $this->assertNull($result['web_route_prefix']);

            $manifest['routes']['web_prefix'] = 'login';
            $files->put($root.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->assertFalse(app(ModuleValidator::class)->inspect($id, base_path('modules'))['valid']);
        } finally {
            $files->deleteDirectory($root);
        }
    }
}
