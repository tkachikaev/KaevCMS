<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\News;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\NewsComments\Livewire\NewsThread;
use KaevCMS\Modules\NewsComments\Models\NewsComment;
use KaevCMS\Modules\NewsComments\Services\NewsCommentModerationService;
use KaevCMS\Modules\NewsComments\Services\NewsCommentService;
use Livewire\Livewire;
use Tests\TestCase;

class NewsCommentsModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('news-comments');
        app(ModuleRuntime::class)->bootModule($module);

        DB::table('module_news_comment_settings')->where('id', 1)->update([
            'enabled' => true,
            'publication_mode' => 'manual_review',
            'min_submission_interval_seconds' => 0,
        ]);
    }

    public function test_owner_approves_new_comment_and_badge_count_decreases(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $owner = Admin::factory()->create();

        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Please approve me.');
        $this->assertSame('pending', $comment->status);
        $this->assertSame(1, app(NewsCommentModerationService::class)->pendingCount());
        $this->assertSame(1, app(ModuleNavigationRegistry::class)->adminBadgeFor('news-comments', $owner));

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/news-comments')
            ->assertOk()
            ->assertSee('Please approve me.')
            ->assertSee(__('module-news-comments::messages.new_pending'));

        $this->post('/admin/extensions/news-comments/comments/'.$comment->id.'/approve')
            ->assertRedirect();

        $this->assertDatabaseHas('module_news_comments', ['id' => $comment->id, 'status' => 'approved']);
        $this->assertSame(0, app(NewsCommentModerationService::class)->pendingCount());
        $this->assertTrue(AuditLog::query()->where('action', 'news_comments.approve')->exists());
    }

    public function test_rejecting_new_comment_keeps_it_out_of_public_view_and_allows_restoring(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $owner = Admin::factory()->create();

        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Unapproved comment.');

        $this->actingAs($owner, 'admin');
        app(NewsCommentModerationService::class)->act($owner, $comment->id, 'reject');

        $this->assertDatabaseHas('module_news_comments', ['id' => $comment->id, 'status' => 'rejected']);
        $this->assertSame(0, app(NewsCommentModerationService::class)->pendingCount());

        $this->actingAs($user);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertDontSee('Unapproved comment.');

        $this->actingAs($owner, 'admin');
        app(NewsCommentModerationService::class)->act($owner, $comment->id, 'restore');
        $this->assertDatabaseHas('module_news_comments', ['id' => $comment->id, 'status' => 'approved']);
    }

    public function test_approving_edited_text_preserves_original_replies(): void
    {
        $news = $this->article();
        $author = User::factory()->create();
        $other = User::factory()->create();
        $owner = Admin::factory()->create();
        $moderation = app(NewsCommentModerationService::class);

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'automatic']);

        $this->actingAs($author);
        $root = app(NewsCommentService::class)->submit($news, $author, 'Excellent server.');

        $this->actingAs($other);
        $reply = app(NewsCommentService::class)->submit($news, $other, 'I agree.', $root->id);

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'manual_review']);

        $this->actingAs($author);
        $this->assertSame('pending_edit',
            app(NewsCommentService::class)->edit($news, $author, $root->id, 'Not great anymore.'));
        $this->assertSame(1, $moderation->pendingCount());
        $this->assertSame('Excellent server.', $root->fresh()->body);

        $this->actingAs($other);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee('Excellent server.')
            ->assertSee('I agree.')
            ->assertDontSee('Not great anymore.');

        $this->actingAs($owner, 'admin');
        $this->post('/admin/extensions/news-comments/comments/'.$root->id.'/approve-edit', [
            'expected_revision_hash' => hash('sha256', 'Not great anymore.'),
        ])->assertRedirect();

        $this->assertDatabaseHas('module_news_comments', [
            'id' => $root->id,
            'status' => 'approved',
            'body' => 'Not great anymore.',
            'pending_body' => null,
        ]);
        $this->assertNotNull($root->fresh()->edited_at);
        $this->assertDatabaseHas('module_news_comments', ['id' => $reply->id, 'parent_id' => $root->id]);
        $this->assertSame(0, $moderation->pendingCount());
    }

    public function test_rejecting_a_revision_preserves_previous_text_and_edited_at(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $owner = Admin::factory()->create();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'automatic']);
        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Initial.');
        app(NewsCommentService::class)->edit($news, $user, $comment->id, 'Approved edit.');
        $editedAt = $comment->fresh()->edited_at?->toDateTimeString();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'manual_review']);
        app(NewsCommentService::class)->edit($news, $user, $comment->id, 'Rejected proposed text.');

        $this->actingAs($owner, 'admin');
        app(NewsCommentModerationService::class)->act($owner, $comment->id, 'reject_edit', hash('sha256', 'Rejected proposed text.'));

        $this->assertSame('Approved edit.', $comment->fresh()->body);
        $this->assertNull($comment->fresh()->pending_body);
        $this->assertSame($editedAt, $comment->fresh()->edited_at?->toDateTimeString());
        $this->assertSame(0, app(NewsCommentModerationService::class)->pendingCount());
    }

    public function test_editor_moderates_only_after_owner_enables_permission_but_cannot_delete_or_update_settings(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $editor = Admin::factory()->editor()->create();
        $owner = Admin::factory()->create();

        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Editor review.');

        $this->actingAs($editor, 'admin');
        $this->get('/admin/extensions/news-comments')->assertForbidden();
        $this->post('/admin/extensions/news-comments/comments/'.$comment->id.'/approve')->assertForbidden();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['allow_editor_moderation' => true]);

        $this->get('/admin/extensions/news-comments')->assertOk();
        $this->post('/admin/extensions/news-comments/comments/'.$comment->id.'/approve')->assertRedirect();
        $this->assertSame('approved', $comment->fresh()->status);
        $this->delete('/admin/extensions/news-comments/comments/'.$comment->id)->assertForbidden();
        $this->put('/admin/extensions/news-comments/news/'.$news->id, ['policy' => 'disabled'])
            ->assertForbidden();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['allow_editor_moderation' => false]);
        $this->post('/admin/extensions/news-comments/comments/'.$comment->id.'/hide')->assertForbidden();

        $this->actingAs($owner, 'admin');
        $this->post('/admin/extensions/news-comments/comments/'.$comment->id.'/hide')->assertRedirect();
    }

    public function test_auditor_is_read_only_but_administrator_may_delete(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $auditor = Admin::factory()->auditor()->create();
        $admin = Admin::factory()->administrator()->create();

        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Private review.');

        $this->actingAs($auditor, 'admin');
        $this->get('/admin/extensions/news-comments')->assertOk();
        $this->post('/admin/extensions/news-comments/comments/'.$comment->id.'/approve')->assertForbidden();
        $this->delete('/admin/extensions/news-comments/comments/'.$comment->id)->assertForbidden();

        $this->actingAs($admin, 'admin');
        $this->post('/admin/extensions/news-comments/comments/'.$comment->id.'/approve')->assertRedirect();
        $this->delete('/admin/extensions/news-comments/comments/'.$comment->id)->assertRedirect();
        $this->assertDatabaseMissing('module_news_comments', ['id' => $comment->id]);
    }

    public function test_deleting_a_referenced_comment_preserves_replies_and_submission_history(): void
    {
        $news = $this->article();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $owner = Admin::factory()->create();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'automatic']);

        $this->actingAs($first);
        $original = app(NewsCommentService::class)->submit($news, $first, 'Original text');
        $this->actingAs($second);
        $reply = app(NewsCommentService::class)->submit($news, $second, 'Someone else answered', $original->id);

        $this->actingAs($owner, 'admin');
        app(NewsCommentModerationService::class)->act($owner, $original->id, 'destroy');

        $this->assertDatabaseHas('module_news_comments', [
            'id' => $original->id,
            'body' => null,
            'pending_body' => null,
            'status' => 'approved',
        ]);
        $this->assertNotNull($original->fresh()->deleted_by_admin_at);
        $this->assertDatabaseHas('module_news_comments', [
            'id' => $reply->id,
            'parent_id' => $original->id,
            'body' => 'Someone else answered',
        ]);
        $this->assertDatabaseCount('module_news_comment_submission_events', 2);
        $this->assertTrue(AuditLog::query()->where('action', 'news_comments.destroy')->exists());

        $this->actingAs($second);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee(__('module-news-comments::messages.deleted_label'))
            ->assertSee('Someone else answered')
            ->assertDontSee('Original text');
    }

    public function test_delete_reply_removes_only_reply_not_parent(): void
    {
        $news = $this->article();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $owner = Admin::factory()->create();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'automatic']);

        $this->actingAs($first);
        $root = app(NewsCommentService::class)->submit($news, $first, 'Root still here');
        $this->actingAs($second);
        $reply = app(NewsCommentService::class)->submit($news, $second, 'Remove this reply', $root->id);

        $this->actingAs($owner, 'admin');
        app(NewsCommentModerationService::class)->act($owner, $reply->id, 'destroy');

        $this->assertDatabaseHas('module_news_comments', ['id' => $root->id]);
        $this->assertDatabaseMissing('module_news_comments', ['id' => $reply->id]);
    }

    public function test_admin_cannot_remove_a_referenced_placeholder_until_replies_are_individually_deleted(): void
    {
        $news = $this->article();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $admin = Admin::factory()->administrator()->create();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'automatic']);

        $this->actingAs($first);
        $original = app(NewsCommentService::class)->submit($news, $first, 'Author deletes own content');
        $this->actingAs($second);
        $reply = app(NewsCommentService::class)->submit($news, $second, 'Other user reply', $original->id);

        $this->actingAs($first);
        app(NewsCommentService::class)->deleteOwn($news, $first, $original->id);
        $this->assertNotNull($original->fresh()->deleted_by_author_at);

        $this->actingAs($admin, 'admin');
        try {
            app(NewsCommentModerationService::class)->act($admin, $original->id, 'destroy');
            $this->fail('Referenced tombstones must not be physically deleted.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('module_news_comments', [
                'id' => $reply->id,
                'parent_id' => $original->id,
            ]);
        }

        app(NewsCommentModerationService::class)->act($admin, $reply->id, 'destroy');
        app(NewsCommentModerationService::class)->act($admin, $original->id, 'destroy');
        $this->assertDatabaseCount('module_news_comments', 0);
    }

    public function test_hide_cancels_outstanding_revision_and_restore_keeps_old_text(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $admin = Admin::factory()->administrator()->create();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'automatic']);
        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Public original');

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'manual_review']);
        app(NewsCommentService::class)->edit($news, $user, $comment->id, 'Pending revision');
        $this->assertSame(1, app(NewsCommentModerationService::class)->pendingCount());

        $this->actingAs($admin, 'admin');
        app(NewsCommentModerationService::class)->act($admin, $comment->id, 'hide');
        $this->assertDatabaseHas('module_news_comments', [
            'id' => $comment->id,
            'status' => 'hidden',
            'body' => 'Public original',
            'pending_body' => null,
        ]);
        $this->assertSame(0, app(NewsCommentModerationService::class)->pendingCount());

        app(NewsCommentModerationService::class)->act($admin, $comment->id, 'restore');
        $this->assertDatabaseHas('module_news_comments', [
            'id' => $comment->id, 'status' => 'approved', 'body' => 'Public original',
        ]);
    }

    public function test_duplicate_approval_and_approval_after_author_deletion_are_rejected(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $admin = Admin::factory()->administrator()->create();

        $this->actingAs($user);
        $new = app(NewsCommentService::class)->submit($news, $user, 'New submission');

        $this->actingAs($admin, 'admin');
        $moderator = app(NewsCommentModerationService::class);
        $moderator->act($admin, $new->id, 'approve');

        try {
            $moderator->act($admin, $new->id, 'approve');
            $this->fail('A duplicate approval should be rejected.');
        } catch (ValidationException) {
            $this->assertSame('approved', $new->fresh()->status);
        }

        $this->actingAs($user);
        app(NewsCommentService::class)->deleteOwn($news, $user, $new->id);

        $this->actingAs($admin, 'admin');
        $this->post('/admin/extensions/news-comments/comments/'.$new->id.'/approve')
            ->assertNotFound();
    }

    public function test_stale_edit_form_cannot_approve_a_different_proposed_text(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $admin = Admin::factory()->create();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'automatic']);
        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Original body');

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'manual_review']);
        app(NewsCommentService::class)->edit($news, $user, $comment->id, 'First proposed edit');

        $this->actingAs($admin, 'admin');
        app(NewsCommentModerationService::class)->act(
            $admin, $comment->id, 'reject_edit', hash('sha256', 'First proposed edit'),
        );

        $this->actingAs($user);
        app(NewsCommentService::class)->edit($news, $user, $comment->id, 'Second proposed edit');

        $this->actingAs($admin, 'admin');
        $this->post('/admin/extensions/news-comments/comments/'.$comment->id.'/approve-edit', [
            'expected_revision_hash' => hash('sha256', 'First proposed edit'),
        ])->assertSessionHasErrors('moderation');

        $this->assertDatabaseHas('module_news_comments', [
            'id' => $comment->id,
            'status' => 'approved',
            'body' => 'Original body',
            'pending_body' => 'Second proposed edit',
        ]);
    }

    public function test_player_deleted_pending_revision_cannot_be_approved(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $admin = Admin::factory()->administrator()->create();

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'automatic']);
        $this->actingAs($user);
        $root = app(NewsCommentService::class)->submit($news, $user, 'Original message');
        $this->actingAs($other);
        app(NewsCommentService::class)->submit($news, $other, 'Preserve my reply', $root->id);

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'manual_review']);
        $this->actingAs($user);
        app(NewsCommentService::class)->edit($news, $user, $root->id, 'Proposed text');
        app(NewsCommentService::class)->deleteOwn($news, $user, $root->id);
        $this->assertDatabaseHas('module_news_comments', [
            'id' => $root->id, 'body' => null, 'pending_body' => null,
        ]);

        $this->actingAs($admin, 'admin');
        $this->expectException(ValidationException::class);
        app(NewsCommentModerationService::class)->act($admin, $root->id, 'approve_edit', hash('sha256', 'Proposed text'));
    }

    public function test_untrusted_html_is_escaped_in_admin_queue(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $admin = Admin::factory()->create();

        $this->actingAs($user);
        app(NewsCommentService::class)->submit($news, $user, '<script>alert(1)</script>');

        $this->actingAs($admin, 'admin')
            ->get('/admin/extensions/news-comments')
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_admin_filters_pending_revisions_and_reviewed_comments(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $admin = Admin::factory()->create();

        $this->actingAs($user);
        $pending = app(NewsCommentService::class)->submit($news, $user, 'Pending.');
        $this->actingAs($admin, 'admin');
        app(NewsCommentModerationService::class)->act($admin, $pending->id, 'approve');
        $this->get('/admin/extensions/news-comments?review=pending')->assertOk()
            ->assertDontSee('Pending.');
        $this->get('/admin/extensions/news-comments?review=approved&news_id='.$news->id)->assertOk()
            ->assertSee('Pending.');

        $this->get('/admin/extensions/news-comments?review=not-real')->assertRedirect();
    }

    private function article(): News
    {
        return News::query()->forceCreate([
            'title' => 'Moderation article',
            'slug' => 'moderation-article-'.uniqid(),
            'body' => '<p>Moderation article.</p>',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
    }
}
