<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Models\UserNotification;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumPosting;
use Tests\TestCase;

class ForumNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_topic_author_gets_one_merged_notification_for_replies(): void
    {
        $author = User::factory()->create(['display_name' => 'TopicOwner']);
        $topic = $this->topic($author, 'Bell topic');

        $this->replyAs(User::factory()->create(['display_name' => 'FirstReplier']), $topic, 'First answer');
        $this->replyAs(User::factory()->create(['display_name' => 'SecondReplier']), $topic, 'Second answer');

        $notification = UserNotification::query()->where('user_id', $author->id)->sole();
        $this->assertSame('forum.topic_reply', $notification->type);
        $this->assertSame(2, $notification->occurrences);
        $this->assertSame('SecondReplier', $notification->parameters['name']);
        $this->assertStringStartsWith('/forum/topics/'.$topic->id.'?page=1#post-', $notification->url);

        $this->actingAs($author, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum')->assertOk()
            ->assertSee('data-forum-bell', false)
            ->assertSee('SecondReplier')
            ->assertSee('Bell topic');
    }

    public function test_quoted_and_answered_authors_are_notified_once_and_never_the_writer(): void
    {
        $owner = User::factory()->create();
        $quoted = User::factory()->create();
        $answered = User::factory()->create();
        $writer = User::factory()->create(['display_name' => 'Writer']);
        $topic = $this->topic($owner, 'Discussion');
        $quotedPost = $this->addReply($quoted, $topic, 'Quoted words');
        $answeredPost = $this->addReply($answered, $topic, 'Answered words');
        $writerPost = $this->addReply($writer, $topic, 'Own earlier words');
        UserNotification::query()->delete();

        $otherTopic = $this->topic(User::factory()->create(), 'Elsewhere');
        $foreignPost = ForumPost::query()->where('topic_id', $otherTopic->id)->firstOrFail();

        $this->replyAs($writer, $topic, 'Quoted words, and @'.$answered->publicName().', here is my answer', [
            'quoted_posts' => [$quotedPost->id, $writerPost->id, $foreignPost->id],
            'replied_posts' => [$answeredPost->id, $quotedPost->id],
        ]);

        $this->assertSame('forum.quote', UserNotification::query()->where('user_id', $quoted->id)->sole()->type);
        $this->assertSame('forum.reply', UserNotification::query()->where('user_id', $answered->id)->sole()->type);
        $this->assertSame('forum.topic_reply', UserNotification::query()->where('user_id', $owner->id)->sole()->type);
        $this->assertSame(0, UserNotification::query()->where('user_id', $writer->id)->count());
        $this->assertSame(0, UserNotification::query()->where('user_id', $otherTopic->author_user_id)->count());
    }

    public function test_forged_address_ids_without_a_quote_or_mention_do_not_notify_other_posters(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->create(['display_name' => 'TargetPlayer']);
        $writer = User::factory()->create();
        $topic = $this->topic($owner, 'Bell spoof test');
        $targetPost = $this->addReply($target, $topic, 'Unique text from target');
        UserNotification::query()->delete();

        $this->replyAs($writer, $topic, 'A normal reply without a quote or mention', [
            'quoted_posts' => [$targetPost->id],
            'replied_posts' => [$targetPost->id],
        ]);

        $this->assertSame(0, UserNotification::query()->where('user_id', $target->id)->count());
        $this->assertSame(1, UserNotification::query()->where('user_id', $owner->id)->count());
    }

    public function test_notification_failure_does_not_undo_a_published_reply(): void
    {
        $owner = User::factory()->create();
        $writer = User::factory()->create();
        $topic = $this->topic($owner, 'Notification failure');

        $this->app->bind(\KaevCMS\Modules\Forum\Services\ForumNotifications::class,
            static function (): never {
                throw new \RuntimeException('Notification service unavailable');
            });

        $this->replyAs($writer, $topic, 'Published despite the notification failure');
        $this->assertSame(1, $topic->fresh()->reply_count);
        $this->assertSame(2, ForumPost::query()->where('topic_id', $topic->id)->count());
    }

    public function test_notifications_stop_exposing_topic_titles_when_access_is_revoked(): void
    {
        $author = User::factory()->create();
        $writer = User::factory()->create();
        $topic = $this->topic($author, 'Confidential topic title');
        $this->replyAs($writer, $topic, 'Normal reply before restriction');

        $center = app(\App\Services\Notifications\PlayerNotificationCenter::class);
        $notification = UserNotification::query()->where('user_id', $author->id)->sole();
        $this->assertSame(1, $center->unreadCount($author));

        $section = ForumSection::query()->findOrFail($topic->section_id);
        $section->forceFill(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators'])->save();

        $this->assertSame(0, $center->unreadCount($author));
        $this->assertCount(0, $center->latest($author));
        $this->assertNull($center->open($author, $notification->id));
        $this->assertNull($notification->fresh()->read_at);

        $section->forceFill(['view_rule' => 'all', 'topic_rule' => 'registered', 'reply_rule' => 'registered'])->save();
        $topic->forceFill(['hidden_at' => now()])->save();

        // Even the same center must not trust a stale permission decision.
        $this->assertCount(0, $center->latest($author));
    }

    public function test_recipients_who_cannot_read_the_section_are_not_notified(): void
    {
        $author = User::factory()->create();
        $section = ForumSection::query()->where('name_ru', 'Общее общение')->firstOrFail();
        $topic = $this->topic($author, 'Soon private');
        $section->update(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators']);
        $moderator = User::factory()->create();
        \Illuminate\Support\Facades\DB::table('module_forum_moderators')->insert([
            'user_id' => $moderator->id, 'assigned_by_admin_id' => \App\Models\Admin::factory()->create()->id, 'created_at' => now(),
        ]);

        $this->replyAs($moderator, $topic, 'Staff only now');

        $this->assertSame(0, UserNotification::query()->where('user_id', $author->id)->count());
    }

    public function test_quoting_a_picture_only_message_notifies_its_author(): void
    {
        $owner = User::factory()->create();
        $artist = User::factory()->create(['display_name' => 'PictureAuthor']);
        $writer = User::factory()->create();
        $topic = $this->topic($owner, 'Screenshots');
        $picture = app(ForumPosting::class)->addReply(new ForumActor('user', user: $artist), $topic->id, '',
            (string) Str::uuid(), '<figure><img src="https://iili.io/abc.png" alt=""></figure>');
        UserNotification::query()->delete();

        $this->replyAs($writer, $topic, 'PictureAuthor wrote: nice screenshot', ['quoted_posts' => [$picture->id]]);
        $this->assertSame('forum.quote', UserNotification::query()->where('user_id', $artist->id)->sole()->type);

        // Without the author's name in the message, a forged quote reference is ignored.
        UserNotification::query()->delete();
        $this->replyAs($writer, $topic, 'Unrelated text', ['quoted_posts' => [$picture->id]]);
        $this->assertSame(0, UserNotification::query()->where('user_id', $artist->id)->count());
    }

    private function topic(User $author, string $title): ForumTopic
    {
        $section = ForumSection::query()->where('name_ru', 'Общее общение')->firstOrFail();

        return app(ForumPosting::class)->createTopic(
            new ForumActor('user', user: $author), $section->id, $title, 'Opening post', (string) Str::uuid(),
        );
    }

    private function addReply(User $author, ForumTopic $topic, string $body): ForumPost
    {
        return app(ForumPosting::class)->addReply(new ForumActor('user', user: $author), $topic->id, $body, (string) Str::uuid());
    }

    /** @param  array<string, list<int>>  $addressed */
    private function replyAs(User $user, ForumTopic $topic, string $body, array $addressed = []): void
    {
        $this->actingAs($user, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->post('/forum/topics/'.$topic->id.'/replies', [
            'body' => $body,
            'submission_key' => (string) Str::uuid(),
        ] + $addressed)->assertRedirect();
    }
}
