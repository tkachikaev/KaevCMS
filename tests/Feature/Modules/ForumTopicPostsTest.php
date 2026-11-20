<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use Tests\TestCase;

final class ForumTopicPostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_posts_show_the_current_author_name_role_number_and_reply_tools(): void
    {
        $starter = User::factory()->create();
        $starter->forceFill(['display_name' => 'OldName'])->save();
        $replier = User::factory()->create();
        $replier->forceFill(['display_name' => 'Replier'])->save();
        $section = ForumSection::query()->where('name_ru', 'Общее общение')->firstOrFail();

        $this->actingAs($starter, 'web')->withSession(['forum.author_mode' => 'user'])
            ->post('/forum/sections/'.$section->id.'/topics', [
                'title' => 'Trade topic',
                'body' => 'Buying a set',
                'submission_key' => (string) Str::uuid(),
            ])->assertRedirect();
        $topic = ForumTopic::query()->firstOrFail();

        $this->actingAs($replier, 'web')->withSession(['forum.author_mode' => 'user'])
            ->post('/forum/topics/'.$topic->id.'/replies', [
                'body' => 'I can sell it',
                'submission_key' => (string) Str::uuid(),
            ])->assertRedirect();

        // The starter renames the account and becomes a moderator after posting.
        $starter->forceFill(['display_name' => 'NewName'])->save();
        DB::table('module_forum_moderators')->insert(['user_id' => $starter->id]);

        $page = $this->get('/forum/topics/'.$topic->id)->assertOk();

        $page->assertSee('NewName')
            ->assertDontSee('OldName')
            ->assertSee(__('module-forum::messages.moderator'))
            ->assertSee(__('module-forum::messages.topic_author'))
            ->assertSee('>#1</a>', false)
            ->assertSee('>#2</a>', false)
            ->assertSee('#post-', false)
            ->assertSee('data-forum-quote', false)
            ->assertSee('data-text="Buying a set"', false)
            ->assertSee('data-forum-mention', false);
    }
}
