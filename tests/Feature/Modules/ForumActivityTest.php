<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumPosting;
use Tests\TestCase;

class ForumActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
        Cache::flush();
    }

    public function test_home_table_lists_only_visible_topics_with_tabs(): void
    {
        $writer = User::factory()->create();
        $this->topic($writer, 'Общее общение', 'Visible public topic');
        $hidden = $this->topic($writer, 'Общее общение', 'Hidden topic');
        $hidden->forceFill(['hidden_at' => now()])->save();
        $private = ForumSection::query()->where('name_ru', 'Вопросы и помощь')->firstOrFail();
        $this->topic($writer, 'Вопросы и помощь', 'Private topic');
        $private->update(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators']);
        Cache::flush();

        $this->get('/forum')->assertOk()
            ->assertSee('data-forum-activity', false)
            ->assertSee('Visible public topic')
            ->assertDontSee('Hidden topic')
            ->assertDontSee('Private topic')
            ->assertDontSee('data-forum-activity-tab="mine"', false);

        $this->actingAs($writer, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum?activity=mine')->assertOk()
            ->assertSee('data-forum-activity-tab="mine"', false)
            ->assertSee('aria-controls="forum-activity-mine" aria-selected="true"', false);
    }

    public function test_owner_switches_the_table_and_sets_the_number_of_topics(): void
    {
        $writer = User::factory()->create();
        foreach (range(1, 4) as $number) {
            $this->topic($writer, 'Общее общение', 'Numbered topic '.$number);
        }

        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin');
        $this->get('/admin/extensions/forum/settings?section=community')->assertOk()
            ->assertSee('name="activity_enabled"', false)
            ->assertSee('name="activity_limit"', false);
        $this->put('/admin/extensions/forum/settings/community', ['activity_enabled' => '1', 'activity_limit' => 2, 'image_hosts' => 'iili.io'])
            ->assertRedirect();
        $this->assertSame(2, (int) DB::table('module_forum_settings')->value('activity_limit'));

        auth('admin')->logout();
        Cache::flush();
        $html = (string) $this->get('/forum')->assertOk()->getContent();
        $latest = explode('data-forum-activity-panel="new"', explode('data-forum-activity-panel="latest"', $html)[1])[0];
        $this->assertSame(2, substr_count($latest, 'forum-activity-row'));

        // Switching off hides the table and keeps the chosen number.
        $this->actingAs($owner, 'admin');
        $this->put('/admin/extensions/forum/settings/community', ['activity_enabled' => '0', 'activity_limit' => 2, 'image_hosts' => 'iili.io'])
            ->assertRedirect();
        foreach ([0, 31] as $invalid) {
            $this->put('/admin/extensions/forum/settings/community', ['activity_enabled' => '1', 'activity_limit' => $invalid, 'image_hosts' => 'iili.io'])
                ->assertSessionHasErrors('activity_limit');
        }
        $this->assertSame(2, (int) DB::table('module_forum_settings')->value('activity_limit'));
        auth('admin')->logout();
        $this->get('/forum')->assertOk()->assertDontSee('data-forum-activity', false);
    }

    public function test_only_the_owner_changes_the_activity_table(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => \App\Auth\AdminRole::Administrator]), 'admin');
        $this->put('/admin/extensions/forum/settings/community', ['activity_enabled' => '0', 'activity_limit' => 5, 'image_hosts' => 'iili.io'])
            ->assertForbidden();
        $this->assertTrue((bool) DB::table('module_forum_settings')->value('activity_enabled'));
    }

    public function test_moderation_refreshes_the_cached_table_at_once(): void
    {
        $topic = $this->topic(User::factory()->create(), 'Общее общение', 'Soon hidden topic');
        $this->get('/forum')->assertOk()->assertSee('Soon hidden topic');

        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin')->withSession([
            'forum.author_mode' => 'admin', 'admin_session_version' => $owner->session_version,
        ]);
        $this->post('/forum/topics/'.$topic->id.'/moderate/hide')->assertRedirect();
        auth('admin')->logout();
        $this->flushSession();

        // No cache flush here: hiding the topic must drop it from the table immediately.
        $this->get('/forum')->assertOk()->assertDontSee('Soon hidden topic');
    }

    private function topic(User $author, string $section, string $title): ForumTopic
    {
        $id = ForumSection::query()->where('name_ru', $section)->firstOrFail()->id;

        return app(ForumPosting::class)->createTopic(
            new ForumActor('user', user: $author), $id, $title, 'Opening post', (string) Str::uuid(),
        );
    }
}
