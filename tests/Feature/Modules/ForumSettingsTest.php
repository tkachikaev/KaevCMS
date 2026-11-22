<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Forum\Models\ForumSection;
use Tests\TestCase;

final class ForumSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_owner_can_save_global_visibility_and_link_delay(): void
    {
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin');
        $url = '/admin/extensions/forum/settings';

        $this->get($url)
            ->assertOk()
            ->assertSee('name="visibility"', false)
            ->assertSee('name="links_after_days"', false)
            ->assertSee(__('module-forum::messages.moderators_title'));

        $this->put($url, ['visibility' => 'registered', 'links_after_days' => 7])
            ->assertRedirect($url);
        $this->assertDatabaseHas('module_forum_settings', ['id' => 1, 'visibility' => 'registered', 'links_after_days' => 7]);
        $this->assertSame(1, AuditLog::query()->where('action', 'forum.settings_updated')->count());

        $this->put($url, ['visibility' => 'registered', 'links_after_days' => 7])->assertRedirect($url);
        $this->assertSame(1, AuditLog::query()->where('action', 'forum.settings_updated')->count());
    }

    public function test_open_forum_link_leaves_the_panel_with_a_full_page_load(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $html = $this->get('/admin/extensions/forum/settings')->assertOk()->getContent();

        // Livewire navigation would keep the panel head and drop the forum styles.
        $this->assertMatchesRegularExpression('~<a class="forum-settings-open" href="[^"]*/forum">~', (string) $html);
        $this->assertDoesNotMatchRegularExpression('~<a[^>]*wire:navigate[^>]*href="[^"]*/forum"~', (string) $html);
    }

    public function test_invalid_global_settings_cannot_be_persisted(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $url = '/admin/extensions/forum/settings';

        foreach ([
            ['visibility' => 'moderators', 'links_after_days' => 0],
            ['visibility' => 'all', 'links_after_days' => -1],
            ['visibility' => 'all', 'links_after_days' => 3651],
            ['visibility' => 'all', 'links_after_days' => '1.5'],
            ['visibility' => 'all'],
        ] as $payload) {
            $this->put($url, $payload)->assertSessionHasErrors();
        }

        $this->assertDatabaseHas('module_forum_settings', ['id' => 1, 'visibility' => 'all', 'links_after_days' => 0]);
    }

    public function test_global_registered_access_blocks_guests_but_not_signed_in_players(): void
    {
        $section = ForumSection::query()->where('name_ru', 'Общее общение')->firstOrFail();
        DB::table('module_forum_settings')->where('id', 1)->update(['visibility' => 'registered']);

        $this->get('/forum')->assertOk()->assertDontSee('Общее общение');
        $this->get('/forum/sections/'.$section->id)->assertNotFound();

        $player = User::factory()->create();
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum')->assertOk()->assertSee('Общее общение');
        $this->get('/forum/sections/'.$section->id)->assertOk();
    }

    public function test_owner_can_find_assign_and_remove_moderator_without_duplicates(): void
    {
        $owner = Admin::factory()->create();
        $player = User::factory()->create(['display_name' => 'ForumHelper']);
        $section = ForumSection::query()->where('name_ru', 'Общее общение')->firstOrFail();
        $section->update(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators']);

        $this->actingAs($owner, 'admin');
        $url = '/admin/extensions/forum/settings';
        $this->get($url)->assertOk()->assertDontSee('ForumHelper');
        $this->get($url.'?q=ForumHelper')->assertOk()->assertSee('ForumHelper');

        $this->post('/admin/extensions/forum/moderators', ['user_id' => $player->id, 'q' => 'ForumHelper'])
            ->assertRedirect($url.'?q=ForumHelper');
        $this->post('/admin/extensions/forum/moderators', ['user_id' => $player->id])
            ->assertRedirect($url);

        $this->assertDatabaseCount('module_forum_moderators', 1);
        $this->assertDatabaseHas('module_forum_moderators', [
            'user_id' => $player->id,
            'assigned_by_admin_id' => $owner->id,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'forum.moderator_assigned')->count());

        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum/sections/'.$section->id)->assertOk();

        // Administration uses the admin guard independently from public author mode.
        $this->actingAs($owner, 'admin');
        $this->delete('/admin/extensions/forum/moderators/'.$player->id)->assertRedirect($url);
        $this->assertDatabaseCount('module_forum_moderators', 0);
        $this->assertSame(1, AuditLog::query()->where('action', 'forum.moderator_removed')->count());
        $this->delete('/admin/extensions/forum/moderators/'.$player->id)->assertNotFound();

        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum/sections/'.$section->id)->assertNotFound();
    }

    public function test_moderators_must_be_existing_active_site_users(): void
    {
        $inactive = User::factory()->create(['is_active' => false]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $url = '/admin/extensions/forum/moderators';
        $this->post($url, ['user_id' => $inactive->id])->assertSessionHasErrors('user_id');
        $this->post($url, ['user_id' => 999999999])->assertSessionHasErrors('user_id');
        $this->post($url, ['user_id' => 'not-a-number'])->assertSessionHasErrors('user_id');
        $this->assertDatabaseCount('module_forum_moderators', 0);
        $this->get('/admin/extensions/forum/settings?q='.$inactive->id)->assertDontSee($inactive->email);
    }

    public function test_only_owner_can_view_and_mutate_forum_settings_and_moderators(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->administrator()->create();
        $editor = Admin::factory()->editor()->create();

        foreach ([$admin, $editor] as $other) {
            $this->actingAs($other, 'admin');
            $this->get('/admin/extensions/forum/settings')->assertForbidden();
            $this->put('/admin/extensions/forum/settings', [
                'visibility' => 'registered', 'links_after_days' => 10,
            ])->assertForbidden();
            $this->post('/admin/extensions/forum/moderators', ['user_id' => $user->id])->assertForbidden();
            $this->delete('/admin/extensions/forum/moderators/'.$user->id)->assertForbidden();
        }

        $this->assertDatabaseHas('module_forum_settings', ['id' => 1, 'visibility' => 'all', 'links_after_days' => 0]);
        $this->assertDatabaseCount('module_forum_moderators', 0);
    }

    public function test_owner_admin_settings_ignore_public_author_mode(): void
    {
        $owner = Admin::factory()->create();
        $player = User::factory()->create();
        $this->actingAs($owner, 'admin')->actingAs($player, 'web')
            ->withSession(['forum.author_mode' => 'user']);

        $this->put('/admin/extensions/forum/settings', [
            'visibility' => 'all', 'links_after_days' => 14,
        ])->assertRedirect();
        $this->assertDatabaseHas('module_forum_settings', ['id' => 1, 'links_after_days' => 14]);
    }

    public function test_owner_manages_allowed_image_hosts(): void
    {
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin');
        $url = '/admin/extensions/forum/settings';
        $sanitizer = app(\App\Services\Html\SafeHtmlSanitizer::class);
        $forum = \App\Services\Html\SafeHtmlSanitizer::PROFILE_FORUM;

        // Defaults come from the module migration.
        $this->get($url.'?section=community')->assertOk()->assertSee('name="image_hosts"', false)->assertSee('iili.io');
        $community = static fn (string $hosts): array => ['activity_enabled' => '1', 'activity_limit' => 10, 'image_hosts' => $hosts];
        $this->assertStringContainsString('<img', $sanitizer->sanitize('<figure><img src="https://iili.io/a.png"></figure>', $forum));

        // Pasted addresses are reduced to host names; duplicates collapse.
        $this->put($url.'/community', $community("https://iili.io/abc.png\nPICS.EXAMPLE.org, pics.example.org"))
            ->assertRedirect($url.'?section=community');
        $this->assertSame(['iili.io', 'pics.example.org'], json_decode((string) DB::table('module_forum_settings')->value('image_hosts'), true));
        $this->assertSame(1, AuditLog::query()->where('action', 'forum.image_hosts_updated')->count());
        $this->assertStringContainsString('<img', $sanitizer->sanitize('<figure><img src="https://pics.example.org/a.png"></figure>', $forum));
        $this->assertStringNotContainsString('<img', $sanitizer->sanitize('<figure><img src="https://i.imgur.com/a.png"></figure>', $forum));

        // IPs, local names, wildcards and ports are refused without changing the list.
        foreach (['127.0.0.1', 'localhost', '*.example.com', 'example.com:8080', 'intranet', 'bad host!'] as $entry) {
            $this->put($url.'/community', $community($entry))->assertSessionHasErrors('image_hosts');
        }
        $this->assertSame(['iili.io', 'pics.example.org'], json_decode((string) DB::table('module_forum_settings')->value('image_hosts'), true));

        // An empty list turns images off: no editor button and nothing survives sanitizing.
        $this->put($url.'/community', $community(''))->assertRedirect($url.'?section=community');
        $this->assertSame([], json_decode((string) DB::table('module_forum_settings')->value('image_hosts'), true));
        $this->assertStringNotContainsString('<img', $sanitizer->sanitize('<figure><img src="https://iili.io/a.png"></figure>', $forum));
        $section = ForumSection::query()->where('name_ru', 'Общее общение')->firstOrFail();
        $this->actingAs(User::factory()->create(), 'web')
            ->get('/forum/sections/'.$section->id.'/topics/new')
            ->assertOk()
            ->assertDontSee('data-editor-link-image ', false);
    }

    public function test_only_the_owner_can_change_image_hosts(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => \App\Auth\AdminRole::Administrator]), 'admin');
        $this->put('/admin/extensions/forum/settings/community', ['activity_enabled' => '1', 'activity_limit' => 10, 'image_hosts' => 'evil.example'])->assertForbidden();
        $this->assertStringNotContainsString('evil.example', (string) DB::table('module_forum_settings')->value('image_hosts'));
    }
}
