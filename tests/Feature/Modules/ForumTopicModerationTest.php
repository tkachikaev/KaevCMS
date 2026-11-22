<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumPosting;
use Tests\TestCase;

final class ForumTopicModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_staff_can_close_reopen_pin_and_unpin_without_changing_reply_counts(): void
    {
        $writer = User::factory()->create();
        $moderator = User::factory()->create();
        $topic = $this->topic($writer, 'General discussion 001');
        $this->assignModerator($moderator);

        $this->asUser($writer);
        $this->post($this->moderationUrl($topic, 'close'))->assertForbidden();

        $this->asUser($moderator);
        $this->post($this->moderationUrl($topic, 'close'))->assertRedirect();
        $this->assertSame('closed', $topic->fresh()->status);

        $this->asUser($writer);
        $this->get('/forum/topics/'.$topic->id)->assertOk()
            ->assertSee(__('module-forum::messages.closed'));
        $this->post('/forum/topics/'.$topic->id.'/replies', [
            'body' => 'Cannot reply to a closed topic',
            'submission_key' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->asUser($moderator);
        $this->post($this->moderationUrl($topic, 'reopen'))->assertRedirect();
        $this->post($this->moderationUrl($topic, 'pin'))->assertRedirect();
        $this->assertSame('open', $topic->fresh()->status);
        $this->assertTrue($topic->fresh()->is_pinned);

        $this->get('/forum/sections/'.$topic->section_id)
            ->assertSee(__('module-forum::messages.pinned'));

        $this->post($this->moderationUrl($topic, 'pin'))->assertRedirect();
        $this->assertSame(1, AuditLog::query()->where('action', 'forum.topic_pin')->count());

        $this->post($this->moderationUrl($topic, 'unpin'))->assertRedirect();
        $this->assertFalse($topic->fresh()->is_pinned);
        $this->assertSame(0, $topic->fresh()->reply_count);
    }

    public function test_hidden_and_soft_deleted_topics_remain_recoverable_only_by_moderators(): void
    {
        $writer = User::factory()->create();
        $moderator = User::factory()->create();
        $topic = $this->topic($writer, 'Recoverable discussion');
        $replyWriter = User::factory()->create();
        app(ForumPosting::class)->addReply(new ForumActor('user', user: $replyWriter),
            $topic->id, 'Reply must survive', (string) Str::uuid());
        $this->assignModerator($moderator);

        $this->asUser($moderator);
        $this->post($this->moderationUrl($topic, 'hide'))->assertRedirect();
        $this->assertNotNull($topic->fresh()->hidden_at);
        $this->get('/forum/sections/'.$topic->section_id.'/moderation')
            ->assertOk()->assertSee('Recoverable discussion');
        $this->get('/forum/topics/'.$topic->id)->assertOk()
            ->assertSee(__('module-forum::messages.hidden_moderator_notice'));

        $this->asUser($writer);
        $this->get('/forum/topics/'.$topic->id)->assertNotFound();
        $this->get('/forum/sections/'.$topic->section_id)
            ->assertDontSee('Recoverable discussion');
        $this->get('/forum/sections/'.$topic->section_id.'/moderation')->assertForbidden();
        $this->get('/forum/search?q=Recoverable')->assertDontSee('Recoverable discussion');

        $this->asUser($moderator);
        $this->post($this->moderationUrl($topic, 'delete'))->assertRedirect();
        $this->assertTrue(ForumTopic::withTrashed()->findOrFail($topic->id)->trashed());
        $this->get('/forum/topics/'.$topic->id)->assertNotFound();
        $this->get('/forum/sections/'.$topic->section_id.'/moderation')
            ->assertOk()->assertSee('Recoverable discussion');

        $this->post($this->moderationUrl($topic, 'restore'))->assertRedirect();
        $this->assertFalse(ForumTopic::withTrashed()->findOrFail($topic->id)->trashed());
        $this->assertNotNull($topic->fresh()->hidden_at);
        $this->post($this->moderationUrl($topic, 'unhide'))->assertRedirect();

        $this->asUser($writer);
        $this->get('/forum/topics/'.$topic->id)
            ->assertOk()->assertSee('Reply must survive');
        $this->assertSame(1, $topic->fresh()->reply_count);
        $this->assertDatabaseCount('module_forum_posts', 2);
    }

    public function test_moderators_cannot_moderate_other_moderators_or_cms_staff_topics(): void
    {
        $owner = Admin::factory()->create();
        $administrator = Admin::factory()->administrator()->create();
        $moderator = User::factory()->create();
        $otherModerator = User::factory()->create();
        $this->assignModerator($moderator, $owner);
        $this->assignModerator($otherModerator, $owner);

        $staffTopic = $this->topic($administrator, 'Staff notice');
        $modTopic = $this->topic($otherModerator, 'Other moderator topic');
        $this->asUser($moderator);

        foreach ([$staffTopic, $modTopic] as $topic) {
            $this->post($this->moderationUrl($topic, 'close'))->assertForbidden();
            $this->post($this->moderationUrl($topic, 'hide'))->assertForbidden();
            $this->post('/forum/topics/'.$topic->id.'/move', [
                'section_id' => $this->section('Архив')->id,
            ])->assertForbidden();
        }
        $this->assertSame('open', $staffTopic->fresh()->status);

        $this->asOwner($owner);
        $this->post($this->moderationUrl($staffTopic, 'close'))->assertRedirect();
        $this->assertSame('closed', $staffTopic->fresh()->status);
    }

    public function test_moderators_cannot_indirectly_hide_staff_replies(): void
    {
        $moderator = User::factory()->create();
        $owner = Admin::factory()->create();
        $admin = Admin::factory()->administrator()->create();
        $writer = User::factory()->create();
        $topic = $this->topic($writer, 'Community thread with staff reply');
        app(ForumPosting::class)->addReply(new ForumActor('admin', admin: $admin),
            $topic->id, 'Official staff response', (string) Str::uuid());

        $this->assignModerator($moderator, $owner);
        $this->asUser($moderator);
        // The topic page offers no hide/delete and explains why.
        $this->get('/forum/topics/'.$topic->id)->assertOk()
            ->assertDontSee('/moderate/hide', false)
            ->assertDontSee('/moderate/delete', false)
            ->assertSee(__('module-forum::messages.moderation_protected_replies'));
        // A forged request gets the same explanation, not an error page, and changes nothing.
        $this->post($this->moderationUrl($topic, 'hide'))->assertRedirect()->assertSessionHasErrors('moderation');
        $this->post($this->moderationUrl($topic, 'delete'))->assertRedirect()->assertSessionHasErrors('moderation');
        $this->assertFalse(ForumTopic::withTrashed()->findOrFail($topic->id)->trashed());

        $target = $this->section('Вопросы и помощь');
        $target->update(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators']);
        $this->post('/forum/topics/'.$topic->id.'/move', [
            'section_id' => $target->id,
        ])->assertSessionHasErrors('section_id');

        $this->assertNull($topic->fresh()->hidden_at);
        $this->assertNotSame($target->id, (int) $topic->fresh()->section_id);
        $this->assertFalse($topic->fresh()->trashed());
        $this->assertSame($this->section('Общее общение')->id, $topic->fresh()->section_id);
    }

    public function test_moderator_can_move_topic_to_read_only_archive_without_losing_posts(): void
    {
        $writer = User::factory()->create();
        $moderator = User::factory()->create();
        $topic = $this->topic($writer, 'Archiveable discussion');
        $archive = $this->section('Архив');
        $this->assignModerator($moderator);
        $this->asUser($moderator);

        $this->post('/forum/topics/'.$topic->id.'/move', [
            'section_id' => $archive->id,
        ])->assertRedirect('/forum/topics/'.$topic->id);

        $this->assertSame($archive->id, $topic->fresh()->section_id);
        $this->assertSame(0, $topic->fresh()->reply_count);
        $this->get('/forum/sections/'.$archive->id)->assertSee('Archiveable discussion');

        $this->asUser($writer);
        $this->get('/forum/topics/'.$topic->id)->assertOk()
            ->assertSee('Archiveable discussion');
        $this->post('/forum/topics/'.$topic->id.'/replies', [
            'body' => 'Archive is read only',
            'submission_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertDatabaseCount('module_forum_posts', 1);
    }

    public function test_moving_private_topic_public_requires_explicit_owner_acknowledgement(): void
    {
        $private = $this->section('Вопросы и помощь');
        $private->update(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators']);
        $public = $this->section('Общее общение');
        $moderator = User::factory()->create();
        $owner = Admin::factory()->create();
        $this->assignModerator($moderator, $owner);
        $topic = $this->topic($moderator, 'Confidential answer', $private);
        $this->asUser($moderator);

        $this->post('/forum/topics/'.$topic->id.'/move', [
            'section_id' => $public->id,
            'confirm_exposure' => '1',
        ])->assertSessionHasErrors('section_id');

        $this->assertSame($private->id, $topic->fresh()->section_id);
        $this->asOwner($owner);
        $this->post('/forum/topics/'.$topic->id.'/move', [
            'section_id' => $public->id,
        ])->assertSessionHasErrors('section_id');

        $this->post('/forum/topics/'.$topic->id.'/move', [
            'section_id' => $public->id,
            'confirm_exposure' => '1',
        ])->assertRedirect('/forum/topics/'.$topic->id);

        $this->assertSame($public->id, $topic->fresh()->section_id);
        $this->get('/forum/sections/'.$public->id)
            ->assertOk()->assertSee('Confidential answer');
    }

    public function test_private_search_filters_title_body_removed_posts_and_deleted_topics(): void
    {
        $writer = User::factory()->create();
        $moderator = User::factory()->create();
        $registered = $this->section('Вопросы и помощь');
        $registered->update(['view_rule' => 'registered', 'topic_rule' => 'registered', 'reply_rule' => 'registered']);
        $private = $this->section('Предложения и идеи');
        $private->update(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators']);
        $this->assignModerator($moderator);

        $public = $this->topic($writer, 'PublicSearch title');
        $registeredTopic = $this->topic($writer, 'RegisteredSearch title', $registered);
        $this->topic($moderator, 'SecretSearch title', $private);
        $bodyTopic = $this->topic($writer, 'Plain title', null, 'BodyOnlyNeedle in message');
        $removed = $this->topic($writer, 'No body visible', null, 'RemovedSearch content');
        $deleted = $this->topic($writer, 'DeletedSearch title');

        // Removed-post placeholders and deleted/hidden topics are not searchable.
        DB::table('module_forum_posts')->where('topic_id', $removed->id)
            ->update(['body_text' => '', 'body_html' => null, 'removed_at' => now()]);
        $this->asUser($moderator);
        $this->post($this->moderationUrl($deleted, 'delete'))->assertRedirect();

        $this->get('/forum/search?q=SecretSearch')->assertOk()->assertSee('SecretSearch title');
        $this->asUser($writer);
        $this->get('/forum/search?q=PublicSearch')->assertOk()->assertSee($public->title);
        $this->get('/forum/search?q=RegisteredSearch')->assertOk()->assertSee($registeredTopic->title);
        $this->get('/forum/search?q=SecretSearch')->assertOk()->assertDontSee('SecretSearch title');
        $this->get('/forum/search?q=BodyOnlyNeedle')->assertOk()->assertSee($bodyTopic->title);
        $this->get('/forum/search?q=RemovedSearch')->assertOk()->assertDontSee($removed->title);
        $this->get('/forum/search?q=DeletedSearch')->assertOk()->assertDontSee($deleted->title);

        DB::table('module_forum_settings')->where('id', 1)->update(['visibility' => 'registered']);
        $this->withSession(['forum.author_mode' => 'guest']);
        $this->get('/forum/search?q=PublicSearch')->assertOk()->assertDontSee($public->title);
    }

    public function test_literal_search_wildcards_do_not_broaden_results(): void
    {
        $writer = User::factory()->create();
        $only = $this->topic($writer, 'Literal Alpha_One');
        $other = $this->topic($writer, 'Literal AlphaXOne');

        $this->get('/forum/search?q=Alpha_')
            ->assertOk()->assertSee($only->title)->assertDontSee($other->title);
    }

    public function test_search_needs_three_letters_and_respects_a_forum_wide_budget(): void
    {
        $writer = User::factory()->create();
        $topic = $this->topic($writer, 'Budgeted searchable topic');

        $this->get('/forum/search?q=Bu')->assertSessionHasErrors('q');
        $this->get('/forum/search?q=Budgeted')->assertOk()->assertSee($topic->title);

        // A repeated query is answered from the cache even when the shared budget is spent.
        foreach (range(1, 130) as $attempt) {
            RateLimiter::hit('forum-search:global', 60);
        }
        $this->get('/forum/search?q=Budgeted')->assertOk()->assertSee($topic->title);
        $this->get('/forum/search?q=Different')->assertOk()
            ->assertSee(__('module-forum::messages.search_busy'));
    }

    public function test_moderation_queue_does_not_reveal_hidden_staff_topics_to_forum_moderators(): void
    {
        $owner = Admin::factory()->create();
        $staff = Admin::factory()->administrator()->create();
        $moderator = User::factory()->create();
        $writer = User::factory()->create();
        $this->assignModerator($moderator, $owner);

        $staffTopic = $this->topic($staff, 'Staff Hidden Plan');
        $regularTopic = $this->topic($writer, 'Ordinary Hidden Plan');

        $this->asOwner($owner);
        $this->post($this->moderationUrl($staffTopic, 'hide'))->assertRedirect();
        $this->post($this->moderationUrl($regularTopic, 'hide'))->assertRedirect();

        $queue = '/forum/sections/'.$staffTopic->section_id.'/moderation';
        $this->get($queue)->assertOk()->assertSee('Staff Hidden Plan');

        $this->asUser($moderator);
        $this->get($queue)->assertOk()
            ->assertDontSee('Staff Hidden Plan')
            ->assertSee('Ordinary Hidden Plan');
        $this->get('/forum/topics/'.$staffTopic->id)->assertNotFound();
    }

    public function test_revoked_moderator_cannot_change_or_recover_topics(): void
    {
        $writer = User::factory()->create();
        $moderator = User::factory()->create();
        $topic = $this->topic($writer, 'Revocable moderator');
        $this->assignModerator($moderator);
        $this->asUser($moderator);
        $this->post($this->moderationUrl($topic, 'hide'))->assertRedirect();

        DB::table('module_forum_moderators')->where('user_id', $moderator->id)->delete();
        $this->get('/forum/topics/'.$topic->id)->assertNotFound();
        $this->post($this->moderationUrl($topic, 'unhide'))->assertForbidden();
        $this->get('/forum/sections/'.$topic->section_id.'/moderation')->assertForbidden();
        $this->assertNotNull($topic->fresh()->hidden_at);
    }

    public function test_moderator_can_hide_own_topic_but_not_another_moderators_reply(): void
    {
        $moderator = User::factory()->create();
        $other = User::factory()->create();
        $this->assignModerator($moderator);
        $this->assignModerator($other);

        $topic = $this->topic($moderator, 'Moderator owned discussion');
        $this->asUser($moderator);
        $this->post($this->moderationUrl($topic, 'hide'))->assertRedirect();
        $this->post($this->moderationUrl($topic, 'unhide'))->assertRedirect();

        app(ForumPosting::class)->addReply(new ForumActor('user', user: $other),
            $topic->id, 'Other moderator response', (string) Str::uuid());

        $this->post($this->moderationUrl($topic, 'hide'))->assertSessionHasErrors('moderation');
        $this->assertNull($topic->fresh()->hidden_at);
    }

    public function test_owner_finds_deleted_topics_in_the_trash_and_restores_them(): void
    {
        $owner = Admin::factory()->create();
        $topic = $this->topic(User::factory()->create(), 'Topic for the trash');
        $this->asOwner($owner);

        // The topic page offers a moderation menu with dialogs for move and delete.
        $this->get('/forum/topics/'.$topic->id)->assertOk()
            ->assertSee('forum-mod-menu', false)
            ->assertSee('id="forum-topic-delete"', false)
            ->assertSee('id="forum-topic-move"', false);

        $this->post($this->moderationUrl($topic, 'delete'))->assertRedirect();
        $this->get('/forum/trash')->assertOk()
            ->assertSee(__('module-forum::messages.deleted_topics'))
            ->assertSee('Topic for the trash')
            ->assertSee('/forum/topics/'.$topic->id.'/moderate/restore', false);

        $this->post($this->moderationUrl($topic, 'restore'))->assertRedirect('/forum/topics/'.$topic->id);
        $this->assertFalse(ForumTopic::withTrashed()->findOrFail($topic->id)->trashed());
        $this->get('/forum/trash')->assertDontSee('Topic for the trash');
    }

    public function test_topic_of_a_deleted_section_waits_for_its_section(): void
    {
        $owner = Admin::factory()->create();
        $topic = $this->topic(User::factory()->create(), 'Topic in a removed section');
        $this->asOwner($owner);
        $this->post($this->moderationUrl($topic, 'delete'))->assertRedirect();
        ForumSection::query()->findOrFail($topic->section_id)->delete();

        $this->get('/forum/trash')->assertOk()
            ->assertSee('Topic in a removed section')
            ->assertSee(__('module-forum::messages.restore_section_first'))
            ->assertDontSee('/forum/topics/'.$topic->id.'/moderate/restore', false);
        $this->from('/forum/trash')->post($this->moderationUrl($topic, 'restore'))
            ->assertRedirect('/forum/trash')->assertSessionHasErrors('restore');
        $this->assertTrue(ForumTopic::withTrashed()->findOrFail($topic->id)->trashed());

        ForumSection::onlyTrashed()->findOrFail($topic->section_id)->restore();
        $this->post($this->moderationUrl($topic, 'restore'))->assertRedirect('/forum/topics/'.$topic->id);
    }

    private function topic(
        User|Admin $author,
        string $title,
        ?ForumSection $section = null,
        string $body = 'Ordinary content'
    ): ForumTopic {
        $section ??= $this->section('Общее общение');

        return app(ForumPosting::class)->createTopic(
            $author instanceof Admin
                ? new ForumActor('admin', admin: $author)
                : new ForumActor('user', user: $author),
            (int) $section->id, $title, $body, (string) Str::uuid(),
        );
    }

    private function section(string $title): ForumSection
    {
        return ForumSection::query()->where('name_ru', $title)->firstOrFail();
    }

    private function assignModerator(User $user, ?Admin $owner = null): void
    {
        DB::table('module_forum_moderators')->insert([
            'user_id' => $user->id,
            'assigned_by_admin_id' => $owner?->id,
            'created_at' => now(),
        ]);
    }

    private function asUser(User $user): void
    {
        $this->actingAs($user, 'web')->withSession(['forum.author_mode' => 'user']);
    }

    private function asOwner(Admin $owner): void
    {
        $this->actingAs($owner, 'admin')->withSession([
            'forum.author_mode' => 'admin',
            'admin_session_version' => $owner->session_version,
        ]);
    }

    private function moderationUrl(ForumTopic $topic, string $action): string
    {
        return '/forum/topics/'.$topic->id.'/moderate/'.$action;
    }
}
