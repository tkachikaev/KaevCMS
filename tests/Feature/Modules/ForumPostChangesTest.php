<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumPosting;
use Tests\TestCase;

final class ForumPostChangesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_f7_schema_keeps_existing_posts_and_adds_private_history(): void
    {
        $this->assertSame(json_decode((string) file_get_contents(base_path('modules/forum/module.json')), true)['version'], app(ModuleManager::class)->inspect('forum')['version']);
        $this->assertTrue(Schema::hasTable('module_forum_post_revisions'));
        foreach (['edit_version', 'removed_at', 'removed_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('module_forum_posts', $column));
        }
    }

    public function test_author_edits_old_post_and_only_staff_can_read_prior_version(): void
    {
        $author = User::factory()->create(['display_name' => 'FirstWriter']);
        $post = $this->createPost($author, 'First private wording');
        $this->asPlayer($author);

        $this->travel(400)->days();
        $this->get('/forum/posts/'.$post->id.'/edit')
            ->assertOk()
            ->assertSee('First private wording')
            ->assertSee('name="version" value="0"', false);

        $this->patch('/forum/posts/'.$post->id, [
            'body_html' => '<p onclick="bad()">Revised <strong>safe</strong> version</p><script>alert("x")</script>',
            'version' => 0,
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame(1, $post->edit_version);
        $this->assertSame('Revised safe version', $post->body_text);
        $this->assertStringContainsString('<strong>safe</strong>', $post->body_html);
        $this->assertStringNotContainsString('onclick', $post->body_html);
        $this->assertStringNotContainsString('<script', $post->body_html);
        $this->assertNotNull($post->edited_at);
        $this->assertDatabaseHas('module_forum_post_revisions', [
            'post_id' => $post->id, 'action' => 'edited', 'body_text' => 'First private wording',
        ]);

        $this->get('/forum/topics/'.$post->topic_id)
            ->assertOk()
            ->assertSee(__('module-forum::messages.edited'))
            ->assertSee('<strong>safe</strong>', false)
            ->assertDontSee('First private wording');
        $this->get('/forum/posts/'.$post->id.'/history')->assertForbidden();

        $owner = Admin::factory()->create();
        $this->asOwner($owner);
        $response = $this->get('/forum/posts/'.$post->id.'/history')
            ->assertOk()
            ->assertSee('First private wording');
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('0', $response->headers->getCacheControlDirective('max-age'));

        $this->assertSame(1, AuditLog::query()->where('action', 'forum.post_edited')->count());
        $this->assertStringNotContainsString('First private wording', AuditLog::query()->where('action', 'forum.post_edited')->firstOrFail()->target_name ?? '');
    }

    public function test_noop_edit_does_not_create_revision_or_change_version(): void
    {
        $author = User::factory()->create();
        $post = $this->createPost($author, 'Unchanged message');
        $this->asPlayer($author);

        $this->patch('/forum/posts/'.$post->id, [
            'body' => 'Unchanged message', 'version' => 0,
        ])->assertRedirect();

        $this->assertSame(0, $post->fresh()->edit_version);
        $this->assertDatabaseCount('module_forum_post_revisions', 0);
    }

    public function test_stale_edit_and_delete_are_rejected_without_losing_newer_content(): void
    {
        $author = User::factory()->create();
        $post = $this->createPost($author, 'Original');
        $this->asPlayer($author);

        $this->patch('/forum/posts/'.$post->id, [
            'body' => 'Current text', 'version' => 0,
        ])->assertRedirect();

        $this->patch('/forum/posts/'.$post->id, [
            'body' => 'Stale edit', 'version' => 0,
        ])->assertSessionHasErrors('version');
        $this->delete('/forum/posts/'.$post->id, [
            'version' => 0,
        ])->assertSessionHasErrors('version');

        $this->assertSame('Current text', $post->fresh()->body_text);
        $this->assertNull($post->fresh()->removed_at);
        $this->assertDatabaseCount('module_forum_post_revisions', 1);
    }

    public function test_deleting_opening_post_keeps_replies_topic_and_localized_tombstone(): void
    {
        $author = User::factory()->create();
        $replyAuthor = User::factory()->create();
        $post = $this->createPost($author, 'Remove this opening message');

        $reply = app(ForumPosting::class)->addReply(
            new ForumActor('user', user: $replyAuthor),
            (int) $post->topic_id, 'Keep this other reply', (string) Str::uuid()
        );

        $this->asPlayer($author);
        $this->delete('/forum/posts/'.$post->id, ['version' => 0])->assertRedirect();

        $post->refresh();
        $this->assertSame('', $post->body_text);
        $this->assertNull($post->body_html);
        $this->assertNotNull($post->removed_at);
        $this->assertSame('author', $post->removed_by);
        $this->assertDatabaseCount('module_forum_posts', 2);
        $this->assertSame(1, $post->topic->reply_count);
        $this->assertSame('Keep this other reply', $reply->fresh()->body_text);
        $this->assertDatabaseHas('module_forum_post_revisions', [
            'post_id' => $post->id,
            'body_text' => 'Remove this opening message',
            'action' => 'deleted',
        ]);

        $this->get('/forum/topics/'.$post->topic_id)
            ->assertOk()
            ->assertSee(__('module-forum::messages.deleted_by_author'))
            ->assertSee('Keep this other reply')
            ->assertDontSee('Remove this opening message');
        $this->get('/forum/posts/'.$post->id.'/edit')->assertForbidden();
        $this->delete('/forum/posts/'.$post->id, ['version' => 1])->assertForbidden();
        $this->assertDatabaseCount('module_forum_post_revisions', 1);
    }

    public function test_only_author_can_change_post_and_closed_topic_blocks_regular_author(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $post = $this->createPost($author, 'Protected');
        $this->asPlayer($other);

        $this->get('/forum/posts/'.$post->id.'/edit')->assertForbidden();
        $this->patch('/forum/posts/'.$post->id, [
            'body' => 'Tampered', 'version' => 0,
        ])->assertForbidden();
        $this->delete('/forum/posts/'.$post->id, ['version' => 0])->assertForbidden();
        $this->get('/forum/posts/'.$post->id.'/history')->assertForbidden();

        $post->topic->update(['status' => 'closed']);
        $this->asPlayer($author);
        $this->get('/forum/posts/'.$post->id.'/edit')->assertForbidden();
        $this->delete('/forum/posts/'.$post->id, ['version' => 0])->assertForbidden();

        $post->topic->update(['status' => 'open', 'hidden_at' => now()]);
        $this->get('/forum/posts/'.$post->id.'/edit')->assertNotFound();
        $this->assertDatabaseCount('module_forum_post_revisions', 0);
    }

    public function test_moderator_can_edit_normal_players_but_never_cms_admin_or_other_moderator(): void
    {
        $owner = Admin::factory()->create();
        $staff = Admin::factory()->administrator()->create();
        $mod = User::factory()->create();
        $otherMod = User::factory()->create();
        $author = User::factory()->create();

        foreach ([$mod, $otherMod] as $member) {
            DB::table('module_forum_moderators')->insert([
                'user_id' => $member->id,
                'assigned_by_admin_id' => $owner->id,
                'created_at' => now(),
            ]);
        }

        $staffPost = $this->createPost($staff, 'CMS administrator post', 'Staff topic');
        $modPost = $this->createPost($otherMod, 'Another moderator post', 'Mod topic');
        $playerPost = $this->createPost($author, 'Normal player post', 'Player topic');

        $this->asPlayer($mod);
        $this->get('/forum/posts/'.$staffPost->id.'/edit')->assertForbidden();
        $this->get('/forum/posts/'.$modPost->id.'/edit')->assertForbidden();
        $this->get('/forum/posts/'.$playerPost->id.'/edit')->assertOk();

        $this->patch('/forum/posts/'.$staffPost->id, [
            'body' => 'Wrong', 'version' => 0,
        ])->assertForbidden();
        $this->delete('/forum/posts/'.$staffPost->id, ['version' => 0])->assertForbidden();

        $this->patch('/forum/posts/'.$playerPost->id, [
            'body' => 'Moderator correction', 'version' => 0,
        ])->assertRedirect();

        $this->asOwner($owner);
        $this->get('/forum/posts/'.$staffPost->id.'/edit')->assertOk();
        $this->get('/forum/posts/'.$staffPost->id.'/history')->assertOk();
        $this->patch('/forum/posts/'.$staffPost->id, [
            'body' => 'Owner correction', 'version' => 0,
        ])->assertRedirect();

        $this->assertSame('Owner correction', $staffPost->fresh()->body_text);
        $this->assertSame('Moderator correction', $playerPost->fresh()->body_text);
    }

    public function test_link_age_restriction_is_rechecked_when_editing_html(): void
    {
        $author = User::factory()->create();
        $post = $this->createPost($author, 'No links here');
        DB::table('module_forum_settings')->where('id', 1)->update(['links_after_days' => 7]);
        $this->asPlayer($author);

        $this->patch('/forum/posts/'.$post->id, [
            'body_html' => '<p>Visit <a href="https://external.example/test">here</a>.</p>',
            'version' => 0,
        ])->assertSessionHasErrors('body');

        $this->assertSame('No links here', $post->fresh()->body_text);
        $this->assertDatabaseCount('module_forum_post_revisions', 0);
    }

    public function test_previous_editions_expire_independently_and_cleanup_works_while_disabled(): void
    {
        $author = User::factory()->create();
        $post = $this->createPost($author, 'Oldest confidential version');
        $this->asPlayer($author);

        $this->patch('/forum/posts/'.$post->id, ['body' => 'Middle confidential version', 'version' => 0])
            ->assertRedirect();
        $this->travel(15)->days();
        $this->patch('/forum/posts/'.$post->id, ['body' => 'Current published version', 'version' => 1])
            ->assertRedirect();

        $owner = Admin::factory()->create();
        $this->asOwner($owner);
        $this->get('/forum/posts/'.$post->id.'/history')
            ->assertSee('Oldest confidential version')
            ->assertSee('Middle confidential version');

        $this->travel(16)->days();
        // Even before cleanup the expired text is inaccessible via HTTP.
        $this->get('/forum/posts/'.$post->id.'/history')
            ->assertDontSee('Oldest confidential version')
            ->assertSee('Middle confidential version');

        app(ModuleManager::class)->disable('forum');
        $this->artisan('kaevcms:forum-revisions-clean', ['--batch' => 100])->assertExitCode(0);
        $this->assertDatabaseCount('module_forum_post_revisions', 1);
        $this->assertSame('Middle confidential version', DB::table('module_forum_post_revisions')->first()->body_text);
        $this->assertSame('Current published version', $post->fresh()->body_text);
        $this->get('/forum/posts/'.$post->id.'/history')->assertNotFound();

        $this->travel(16)->days();
        $this->artisan('kaevcms:forum-revisions-clean', ['--batch' => 100])->assertExitCode(0);
        $this->assertDatabaseCount('module_forum_post_revisions', 0);
        $this->assertSame('Current published version', $post->fresh()->body_text);
    }

    public function test_cleanup_drains_every_expired_revision_even_when_batch_is_one(): void
    {
        $author = User::factory()->create();
        $post = $this->createPost($author, 'First version');
        $this->asPlayer($author);

        foreach (['Second version', 'Third version', 'Fourth version'] as $index => $body) {
            $this->patch('/forum/posts/'.$post->id, [
                'body' => $body,
                'version' => $index,
            ])->assertRedirect();
        }

        $this->assertDatabaseCount('module_forum_post_revisions', 3);
        $this->travel(31)->days();
        app(ModuleManager::class)->disable('forum');

        $this->artisan('kaevcms:forum-revisions-clean', ['--batch' => 1])->assertExitCode(0);
        $this->assertDatabaseCount('module_forum_post_revisions', 0);
        $this->assertSame('Fourth version', $post->fresh()->body_text);
    }

    private function createPost(User|Admin $author, string $text, string $title = 'Editable topic'): ForumPost
    {
        $actor = $author instanceof Admin
            ? new ForumActor('admin', admin: $author)
            : new ForumActor('user', user: $author);

        $section = ForumSection::query()->where('name_ru', 'Общее общение')->firstOrFail();
        $topic = app(ForumPosting::class)->createTopic(
            $actor, (int) $section->id, $title, $text, (string) Str::uuid()
        );

        return ForumPost::query()->where('topic_id', $topic->id)->firstOrFail();
    }

    private function asPlayer(User $player): void
    {
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
    }

    private function asOwner(Admin $owner): void
    {
        $this->actingAs($owner, 'admin')->withSession(['forum.author_mode' => 'admin']);
    }
}
