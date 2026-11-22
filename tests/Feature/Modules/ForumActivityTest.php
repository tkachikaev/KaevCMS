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

    public function test_owner_sets_the_number_of_topics_or_hides_the_table(): void
    {
        $writer = User::factory()->create();
        foreach (range(1, 4) as $number) {
            $this->topic($writer, 'Общее общение', 'Numbered topic '.$number);
        }

        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin');
        $this->put('/admin/extensions/forum/settings', [
            'visibility' => 'all', 'links_after_days' => 0, 'activity_limit' => 2,
        ])->assertRedirect();
        $this->assertSame(2, (int) DB::table('module_forum_settings')->value('activity_limit'));

        auth('admin')->logout();
        Cache::flush();
        $html = (string) $this->get('/forum')->assertOk()->getContent();
        $latest = explode('data-forum-activity-panel="new"', explode('data-forum-activity-panel="latest"', $html)[1])[0];
        $this->assertSame(2, substr_count($latest, 'forum-activity-row'));

        $this->actingAs($owner, 'admin');
        $this->put('/admin/extensions/forum/settings', [
            'visibility' => 'all', 'links_after_days' => 0, 'activity_limit' => 0,
        ])->assertRedirect();
        $this->put('/admin/extensions/forum/settings', [
            'visibility' => 'all', 'links_after_days' => 0, 'activity_limit' => 31,
        ])->assertSessionHasErrors('activity_limit');
        auth('admin')->logout();
        $this->get('/forum')->assertOk()->assertDontSee('data-forum-activity', false);
    }

    private function topic(User $author, string $section, string $title): ForumTopic
    {
        $id = ForumSection::query()->where('name_ru', $section)->firstOrFail()->id;

        return app(ForumPosting::class)->createTopic(
            new ForumActor('user', user: $author), $id, $title, 'Opening post', (string) Str::uuid(),
        );
    }
}
