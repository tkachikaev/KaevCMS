<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumPosting;
use Tests\TestCase;

final class ForumPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_f4_schema_and_player_topic_creation_with_safe_output(): void
    {
        $this->assertTrue(Schema::hasTable('module_forum_topics'));
        $this->assertTrue(Schema::hasTable('module_forum_posts'));

        $player = User::factory()->create(['display_name' => 'PublicHero', 'name' => 'secret-account-name']);
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        $section = $this->section('Общее общение');

        $this->get('/forum/sections/'.$section->id)->assertOk()->assertSee('Новая тема');
        $this->get('/forum/sections/'.$section->id.'/topics/new')->assertOk();

        $key = (string) Str::uuid();
        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData(
            title: 'Первое обсуждение',
            body: "Привет!\n<script>alert('xss')</script>",
            key: $key,
        ))->assertRedirect();

        $topic = ForumTopic::query()->firstOrFail();
        $post = ForumPost::query()->firstOrFail();
        $this->assertSame($topic->id, $post->topic_id);
        $this->assertSame('user', $topic->author_type);
        $this->assertSame($player->id, $topic->author_user_id);
        $this->assertSame('PublicHero', $post->author_name);
        $this->assertSame(0, $topic->reply_count);
        $this->assertNull($post->author_role);

        $this->get('/forum/topics/'.$topic->id)
            ->assertOk()
            ->assertSee('Первое обсуждение')
            ->assertSee('PublicHero')
            ->assertDontSee('secret-account-name')
            ->assertSee('&lt;script&gt;alert', false)
            ->assertDontSee("<script>alert('xss')</script>", false);

        $this->get('/forum/sections/'.$section->id)->assertSee('Первое обсуждение');
        $this->get('/forum')->assertSee('Тем: 1');
    }

    public function test_guest_cannot_post_but_may_read_public_topics(): void
    {
        $section = $this->section('Общее общение');
        $this->get('/forum/sections/'.$section->id)->assertOk()->assertDontSee('Новая тема');
        $this->get('/forum/sections/'.$section->id.'/topics/new')->assertForbidden();
        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData())->assertForbidden();
        $this->assertDatabaseCount('module_forum_topics', 0);

        $player = User::factory()->create();
        $topic = app(ForumPosting::class)->createTopic(
            new ForumActor('user', user: $player),
            $section->id,
            'Public discussion',
            'Visible to everyone',
            (string) Str::uuid(),
        );
        $this->get('/forum/topics/'.$topic->id)->assertOk()->assertSee('Visible to everyone');
    }

    public function test_owner_may_post_news_but_administrator_cannot_and_archive_is_read_only(): void
    {
        $owner = Admin::factory()->create(['name' => 'GameOwner']);
        $admin = Admin::factory()->administrator()->create(['name' => 'OtherAdmin']);
        $news = $this->section('Новости и объявления');
        $archive = $this->section('Архив');

        $this->actingAs($owner, 'admin')->withSession(['forum.author_mode' => 'admin']);
        $this->post('/forum/sections/'.$news->id.'/topics', $this->topicData(title: 'News from the owner'))
            ->assertRedirect();
        $topic = ForumTopic::query()->firstOrFail();
        $this->assertSame('admin', $topic->author_type);
        $this->assertSame('GameOwner', $topic->author_name);
        $this->get('/forum/topics/'.$topic->id)->assertSee('Администратор');

        $this->post('/forum/topics/'.$topic->id.'/replies', $this->replyData())->assertForbidden();
        $this->post('/forum/sections/'.$archive->id.'/topics', $this->topicData())->assertForbidden();

        $this->actingAs($admin, 'admin')->withSession(['forum.author_mode' => 'admin']);
        $this->get('/forum/sections/'.$news->id.'/topics/new')->assertForbidden();
        $this->post('/forum/sections/'.$news->id.'/topics', $this->topicData())->assertForbidden();
        $this->post('/forum/sections/'.$archive->id.'/topics', $this->topicData())->assertForbidden();
        $this->assertDatabaseCount('module_forum_topics', 1);
    }

    public function test_replies_are_chronological_and_repeated_submission_is_idempotent(): void
    {
        $section = $this->section('Общее общение');
        $owner = User::factory()->create(['display_name' => 'OriginalPoster']);
        $other = User::factory()->create(['display_name' => 'ReplyAuthor']);
        $first = app(ForumPosting::class)->createTopic(
            new ForumActor('user', user: $owner),
            $section->id,
            'A thread with replies',
            'First message',
            (string) Str::uuid(),
        );

        $this->actingAs($other, 'web')->withSession(['forum.author_mode' => 'user']);
        $key = (string) Str::uuid();
        $this->post('/forum/topics/'.$first->id.'/replies', $this->replyData('Second message', $key))
            ->assertRedirect();
        $this->post('/forum/topics/'.$first->id.'/replies', $this->replyData('Second message', $key))
            ->assertRedirect();

        $this->assertDatabaseCount('module_forum_posts', 2);
        $this->assertSame(1, $first->fresh()->reply_count);
        $this->assertSame('ReplyAuthor', $first->fresh()->last_author_name);
        $this->get('/forum/topics/'.$first->id)
            ->assertSeeInOrder(['First message', 'Second message'])
            ->assertSee('ReplyAuthor');

        $topicKey = (string) Str::uuid();
        $data = $this->topicData('Repeated', 'Text of the topic', $topicKey);
        $this->post('/forum/sections/'.$section->id.'/topics', $data)->assertRedirect();
        $this->post('/forum/sections/'.$section->id.'/topics', $data)->assertRedirect();
        $this->assertDatabaseCount('module_forum_topics', 2);
        $this->assertDatabaseCount('module_forum_posts', 3);
    }

    public function test_direct_topic_links_respect_private_section_and_global_visibility(): void
    {
        $owner = Admin::factory()->create();
        $section = $this->section('Общее общение');
        $section->update(['view_rule' => 'moderators', 'topic_rule' => 'owner', 'reply_rule' => 'nobody']);
        $topic = app(ForumPosting::class)->createTopic(
            new ForumActor('admin', admin: $owner),
            $section->id,
            'Private title',
            'Private message',
            (string) Str::uuid(),
        );

        $this->get('/forum')->assertDontSee('Private title');
        $this->get('/forum/sections/'.$section->id)->assertNotFound();
        $this->get('/forum/topics/'.$topic->id)->assertNotFound();
        $this->post('/forum/topics/'.$topic->id.'/replies', $this->replyData())->assertNotFound();

        $player = User::factory()->create();
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum/topics/'.$topic->id)->assertNotFound();
        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData())->assertNotFound();

        $this->actingAs($owner, 'admin')->withSession(['forum.author_mode' => 'admin']);
        $this->get('/forum/topics/'.$topic->id)->assertOk()->assertSee('Private title');

        DB::table('module_forum_settings')->where('id', 1)->update(['visibility' => 'registered']);
        $this->get('/forum/topics/'.$topic->id)->assertOk();
    }

    public function test_hidden_or_deleted_topics_and_deleted_sections_do_not_leak(): void
    {
        $section = $this->section('Общее общение');
        $player = User::factory()->create();
        $actor = new ForumActor('user', user: $player);
        $topic = app(ForumPosting::class)->createTopic($actor, $section->id, 'Hidden discussion', 'Hidden message', (string) Str::uuid());

        $topic->forceFill(['hidden_at' => now()])->save();
        $this->get('/forum/sections/'.$section->id)->assertDontSee('Hidden discussion');
        $this->get('/forum/topics/'.$topic->id)->assertNotFound();
        $this->post('/forum/topics/'.$topic->id.'/replies', $this->replyData())->assertNotFound();

        $topic->forceFill(['hidden_at' => null])->save();
        $section->delete();
        $this->get('/forum/topics/'.$topic->id)->assertNotFound();
        $this->get('/forum/sections/'.$section->id)->assertNotFound();
    }

    public function test_changed_permissions_and_closed_topics_block_new_posts(): void
    {
        $section = $this->section('Общее общение');
        $player = User::factory()->create();
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum/sections/'.$section->id.'/topics/new')->assertOk();

        $section->update(['topic_rule' => 'owner']);
        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData())->assertForbidden();

        $section->update(['topic_rule' => 'registered']);
        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData())->assertRedirect();
        $topic = ForumTopic::query()->firstOrFail();

        $topic->forceFill(['status' => 'closed'])->save();
        $this->get('/forum/topics/'.$topic->id)->assertSee('только для чтения');
        $this->post('/forum/topics/'.$topic->id.'/replies', $this->replyData())->assertForbidden();

        $topic->forceFill(['status' => 'open'])->save();
        $section->update(['reply_rule' => 'nobody']);
        $this->post('/forum/topics/'.$topic->id.'/replies', $this->replyData())->assertForbidden();
        $this->assertSame(0, $topic->fresh()->reply_count);
    }

    public function test_configured_link_delay_applies_to_replies_and_topic_body(): void
    {
        $section = $this->section('Общее общение');
        $player = User::factory()->create(['display_name' => 'Newbie']);
        DB::table('module_forum_settings')->where('id', 1)->update(['links_after_days' => 7]);
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);

        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData(body: 'See https://example.com'))
            ->assertSessionHasErrors('body');

        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData(body: 'See www.example.com'))
            ->assertSessionHasErrors('body');

        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData(body: 'Ordinary message'))
            ->assertRedirect();
        $topic = ForumTopic::query()->firstOrFail();
        $this->post('/forum/topics/'.$topic->id.'/replies', $this->replyData('example.com is here'))
            ->assertSessionHasErrors('body');

        $player->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->post('/forum/topics/'.$topic->id.'/replies', $this->replyData('https://example.com is now allowed'))
            ->assertRedirect();
        $this->assertSame(1, $topic->fresh()->reply_count);
    }

    public function test_moderator_can_post_in_moderator_section_but_unverified_user_cannot(): void
    {
        $section = $this->section('Общее общение');
        $section->update(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators']);
        $moderator = User::factory()->create(['display_name' => 'ModName']);
        DB::table('module_forum_moderators')->insert(['user_id' => $moderator->id, 'created_at' => now()]);
        $this->actingAs($moderator, 'web')->withSession(['forum.author_mode' => 'user']);

        $this->post('/forum/sections/'.$section->id.'/topics', $this->topicData('Moderator message'))
            ->assertRedirect();
        $topic = ForumTopic::query()->firstOrFail();
        $this->get('/forum/topics/'.$topic->id)->assertSee('Модератор');

        $section->update(['view_rule' => 'all', 'topic_rule' => 'registered', 'reply_rule' => 'registered']);
        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->post('/forum/topics/'.$topic->id.'/replies', $this->replyData())
            ->assertSessionHasErrors('body');
    }

    public function test_topic_and_post_pagination_have_stable_page_boundaries(): void
    {
        $section = $this->section('Общее общение');
        $player = User::factory()->create();
        $actor = new ForumActor('user', user: $player);
        $posting = app(ForumPosting::class);

        for ($i = 1; $i <= 22; $i++) {
            $posting->createTopic($actor, $section->id, 'Topic '.$i, 'Opening post', (string) Str::uuid());
        }

        $this->get('/forum/sections/'.$section->id)->assertOk()->assertSee('Topic 22</a>', false)->assertDontSee('Topic 1</a>', false);
        $this->get('/forum/sections/'.$section->id.'?page=2')->assertOk()->assertSee('Topic 1</a>', false)->assertDontSee('Topic 22</a>', false);

        $topic = ForumTopic::query()->where('title', 'Topic 1')->firstOrFail();
        for ($i = 1; $i <= 17; $i++) {
            $posting->addReply($actor, $topic->id, 'Reply number '.$i, (string) Str::uuid());
        }

        $this->get('/forum/topics/'.$topic->id)->assertOk()->assertSee('Reply number 14')->assertDontSee('Reply number 17');
        $this->get('/forum/topics/'.$topic->id.'?page=2')->assertOk()->assertSee('Reply number 17')->assertDontSee('Reply number 14');
        $this->assertSame(17, $topic->fresh()->reply_count);
    }

    /** @return array{title:string, body:string, submission_key:string} */
    private function topicData(
        string $title = 'A reasonable title',
        string $body = 'A reasonable opening post',
        ?string $key = null,
    ): array {
        return ['title' => $title, 'body' => $body, 'submission_key' => $key ?? (string) Str::uuid()];
    }

    /** @return array{body:string, submission_key:string} */
    private function replyData(string $body = 'An ordinary reply', ?string $key = null): array
    {
        return ['body' => $body, 'submission_key' => $key ?? (string) Str::uuid()];
    }

    private function section(string $name): ForumSection
    {
        return ForumSection::query()->where('name_ru', $name)->firstOrFail();
    }
}
