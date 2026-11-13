<?php

namespace Tests\Feature\Modules;

use App\Models\News;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\NewsComments\Livewire\NewsThread;
use KaevCMS\Modules\NewsComments\Models\NewsComment;
use KaevCMS\Modules\NewsComments\Services\NewsCommentService;
use Livewire\Livewire;
use Tests\TestCase;

class NewsCommentsPlayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('news-comments');
        app(ModuleRuntime::class)->bootModule($module);

        DB::table('module_news_comment_settings')->where('id', 1)->update([
            'enabled' => true,
            'publication_mode' => 'automatic',
            'min_submission_interval_seconds' => 0,
        ]);
    }

    public function test_player_can_reply_to_any_approved_message_in_one_flat_list(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->set('body', 'Great server!')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Great server!');

        $original = NewsComment::query()->firstOrFail();
        $this->assertSame('approved', $original->status);
        $this->assertNull($original->parent_id);

        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->call('openReply', $original->id)
            ->set('replyBody', 'I agree')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertSee('I agree');

        $reply = NewsComment::query()->whereNotNull('parent_id')->firstOrFail();
        $this->assertSame($original->id, $reply->parent_id);

        $third = app(NewsCommentService::class)->submit($news, $user, 'A reply to the reply', $reply->id);
        $this->assertSame($reply->id, $third->parent_id);

        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee('Great server!')
            ->assertSee('I agree')
            ->assertSee('A reply to the reply')
            ->assertSee(__('module-news-comments::messages.replying_to'));

        $this->assertDatabaseCount('module_news_comments', 3);
        $this->assertDatabaseCount('module_news_comment_submission_events', 3);
    }

    public function test_manual_review_hides_new_comments_from_others_but_not_the_author(): void
    {
        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'manual_review']);

        $news = $this->article();
        $author = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($author);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->set('body', 'Message awaiting moderation')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Message awaiting moderation')
            ->assertSee(__('module-news-comments::messages.pending_label'));

        $this->assertDatabaseHas('module_news_comments', ['status' => 'pending']);
        $this->actingAs($other);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertDontSee('Message awaiting moderation');

        $this->get('/news/'.$news->slug)
            ->assertOk()
            ->assertDontSee('Message awaiting moderation');
    }

    public function test_manual_edit_keeps_old_body_and_replies_visible_until_review(): void
    {
        $news = $this->article();
        $author = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($author);
        $root = app(NewsCommentService::class)->submit($news, $author, 'Excellent server');

        $this->actingAs($other);
        app(NewsCommentService::class)->submit($news, $other, 'Agreed', $root->id);

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'manual_review']);

        $this->actingAs($author);
        $status = app(NewsCommentService::class)->edit($news, $author, $root->id, 'Not great anymore');

        $this->assertSame('pending_edit', $status);
        $this->assertDatabaseHas('module_news_comments', [
            'id' => $root->id,
            'status' => 'approved',
            'body' => 'Excellent server',
            'pending_body' => 'Not great anymore',
        ]);

        $this->actingAs($other);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee('Excellent server')
            ->assertSee('Agreed')
            ->assertDontSee('Not great anymore');

        $this->actingAs($author);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee('Not great anymore');

        $this->expectException(ValidationException::class);
        app(NewsCommentService::class)->edit($news, $author, $root->id, 'Yet another edit');
    }

    public function test_automatic_edits_require_five_minutes_and_display_edited_marker(): void
    {
        $news = $this->article();
        $author = User::factory()->create();
        $this->actingAs($author);
        $service = app(NewsCommentService::class);
        $comment = $service->submit($news, $author, 'Original text');

        $this->assertSame('approved', $service->edit($news, $author, $comment->id, 'Updated text'));
        $this->assertDatabaseHas('module_news_comments', [
            'id' => $comment->id,
            'body' => 'Updated text',
            'pending_body' => null,
        ]);

        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee(__('module-news-comments::messages.edited_label'));

        try {
            $service->edit($news, $author, $comment->id, 'Second change');
            $this->fail('Expected the five-minute edit interval to prevent a rapid second edit.');
        } catch (ValidationException) {
            $this->assertSame('Updated text', $comment->fresh()->body);
        }

        $this->travel(5)->minutes();

        $this->assertSame('approved', $service->edit($news, $author, $comment->id, 'Second change'));
    }

    public function test_deleting_own_comment_preserves_other_users_replies_without_preserving_text(): void
    {
        $news = $this->article();
        $author = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($author);
        $root = app(NewsCommentService::class)->submit($news, $author, 'Delete my private text');

        $this->actingAs($other);
        $reply = app(NewsCommentService::class)->submit($news, $other, 'Keep this reply', $root->id);

        DB::table('module_news_comments')->where('id', $root->id)
            ->update(['pending_body' => 'Pending private version', 'pending_edited_at' => now()]);

        $this->actingAs($author);
        app(NewsCommentService::class)->deleteOwn($news, $author, $root->id);

        $this->assertDatabaseHas('module_news_comments', [
            'id' => $root->id,
            'body' => null,
            'pending_body' => null,
        ]);
        $this->assertDatabaseHas('module_news_comments', ['id' => $reply->id, 'body' => 'Keep this reply']);

        $this->actingAs($other);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee(__('module-news-comments::messages.deleted_label'))
            ->assertSee('Keep this reply')
            ->assertDontSee('Delete my private text')
            ->assertDontSee('Pending private version');

        $this->assertDatabaseCount('module_news_comment_submission_events', 2);
    }

    public function test_deleting_a_comment_without_replies_erases_it_but_not_quota_history(): void
    {
        $news = $this->article();
        $author = User::factory()->create();
        $this->actingAs($author);

        DB::table('module_news_comment_settings')->where('id', 1)->update([
            'max_comments_per_day' => 1,
            'max_comments_per_news_per_day' => 1,
        ]);

        $comment = app(NewsCommentService::class)->submit($news, $author, 'First comment');
        app(NewsCommentService::class)->deleteOwn($news, $author, $comment->id);
        $this->assertDatabaseCount('module_news_comments', 0);
        $this->assertDatabaseCount('module_news_comment_submission_events', 1);

        $this->expectException(ValidationException::class);
        app(NewsCommentService::class)->submit($news, $author, 'A second comment');
    }

    public function test_hidden_article_and_disabled_module_reject_player_mutations(): void
    {
        $news = $this->article();
        $author = User::factory()->create();
        $this->actingAs($author);

        $news->update(['is_published' => false]);

        try {
            app(NewsCommentService::class)->submit($news, $author, 'Not allowed');
            $this->fail('An unpublished news article accepted a comment.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $news->update(['is_published' => true]);
        app(ModuleManager::class)->disable('news-comments');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(NewsCommentService::class)->submit($news, $author, 'Module disabled');
    }

    public function test_editing_toggle_is_rechecked_after_the_player_opens_an_edit_form(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Original');

        $component = Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->call('openEdit', $comment->id)
            ->assertSet('editingId', $comment->id);

        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['allow_user_editing' => false]);

        $component->set('editingBody', 'Trying to bypass the toggle')
            ->call('saveEdit')
            ->assertHasErrors('editingBody');

        $this->assertSame('Original', $comment->fresh()->body);
    }

    public function test_user_cannot_edit_or_delete_someone_elses_comment(): void
    {
        $news = $this->article();
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $this->actingAs($owner);
        $comment = app(NewsCommentService::class)->submit($news, $owner, 'Protected');

        $this->actingAs($attacker);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertDontSee(__('module-news-comments::messages.edit_pending_label'));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(NewsCommentService::class)->deleteOwn($news, $attacker, $comment->id);
    }

    public function test_plain_text_is_escaped_in_public_output(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $this->actingAs($user);
        app(NewsCommentService::class)->submit($news, $user, '<script>alert(1)</script>');

        $output = Livewire::test(NewsThread::class, ['newsId' => $news->id])->html();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
    }

    public function test_reply_cannot_target_a_comment_in_another_news_article(): void
    {
        $first = $this->article();
        $second = $this->article();
        $user = User::factory()->create();
        $this->actingAs($user);
        $parent = app(NewsCommentService::class)->submit($first, $user, 'Comment in first news');

        $this->expectException(ValidationException::class);
        app(NewsCommentService::class)->submit($second, $user, 'Illegal cross-news reply', $parent->id);
    }

    public function test_pending_new_comment_cannot_be_edited_while_awaiting_approval(): void
    {
        DB::table('module_news_comment_settings')->where('id', 1)
            ->update(['publication_mode' => 'manual_review']);

        $news = $this->article();
        $user = User::factory()->create();
        $this->actingAs($user);
        $comment = app(NewsCommentService::class)->submit($news, $user, 'Waiting');

        $this->expectException(ValidationException::class);
        app(NewsCommentService::class)->edit($news, $user, $comment->id, 'Changed while pending');
    }

    public function test_duplicate_submission_is_blocked_even_with_zero_minimum_interval(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $this->actingAs($user);

        $service = app(NewsCommentService::class);
        $service->submit($news, $user, 'Repeated payload');

        try {
            $service->submit($news, $user, 'Repeated payload');
            $this->fail('Expected the duplicate submission to be blocked.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('module_news_comments', 1);
            $this->assertDatabaseCount('module_news_comment_submission_events', 1);
        }
    }

    public function test_reference_navigation_finds_original_comment_across_flat_pages(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::table('module_news_comment_settings')->where('id', 1)->update([
            'max_comments_per_day' => 30,
            'max_comments_per_news_per_day' => 30,
            'min_submission_interval_seconds' => 0,
        ]);

        $service = app(NewsCommentService::class);
        $original = $service->submit($news, $user, 'Original on second page');
        for ($i = 1; $i <= 16; $i++) {
            $service->submit($news, $user, 'Later message '.$i, $original->id);
        }

        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertDontSee('Original on second page')
            ->call('jumpTo', $original->id)
            ->assertSet('highlightedCommentId', $original->id)
            ->assertSee('Original on second page')
            ->assertSee('news-comments-highlighted');
    }

    public function test_deleting_a_reply_with_replies_keeps_its_replies_and_a_placeholder(): void
    {
        $news = $this->article();
        $author = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($author);
        $first = app(NewsCommentService::class)->submit($news, $author, 'First');
        $middle = app(NewsCommentService::class)->submit($news, $author, 'Middle', $first->id);

        $this->actingAs($second);
        $last = app(NewsCommentService::class)->submit($news, $second, 'Last', $middle->id);

        $this->actingAs($author);
        app(NewsCommentService::class)->deleteOwn($news, $author, $middle->id);

        $this->assertDatabaseHas('module_news_comments', [
            'id' => $middle->id,
            'body' => null,
        ]);
        $this->assertDatabaseHas('module_news_comments', [
            'id' => $last->id,
            'parent_id' => $middle->id,
        ]);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee('First')
            ->assertSee('Last')
            ->assertSee(__('module-news-comments::messages.deleted_label'))
            ->assertDontSee('Middle');
    }

    public function test_admin_deleted_comment_cannot_be_edited_or_replied_to_by_stale_forms(): void
    {
        $news = $this->article();
        $author = User::factory()->create();
        $other = User::factory()->create();
        $admin = \App\Models\Admin::factory()->administrator()->create();

        $this->actingAs($author);
        $original = app(NewsCommentService::class)->submit($news, $author, 'This will be removed');

        $this->actingAs($other);
        $reply = app(NewsCommentService::class)->submit($news, $other, 'Keep my answer', $original->id);

        $this->actingAs($admin, 'admin');
        app(\KaevCMS\Modules\NewsComments\Services\NewsCommentModerationService::class)
            ->act($admin, $original->id, 'destroy');

        $this->assertNotNull($original->fresh()->deleted_by_admin_at);
        $this->assertDatabaseHas('module_news_comments', [
            'id' => $reply->id,
            'parent_id' => $original->id,
        ]);

        $this->actingAs($author);
        try {
            app(NewsCommentService::class)->edit($news, $author, $original->id, 'Trying to restore');
            $this->fail('A deleted comment was edited after moderation.');
        } catch (ValidationException) {
            $this->assertNull($original->fresh()->body);
        }

        $this->actingAs($other);
        try {
            app(NewsCommentService::class)->submit($news, $other, 'Another reply', $original->id);
            $this->fail('A deleted comment accepted a new reply.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('module_news_comments', 2);
        }

        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee('Keep my answer')
            ->assertSee(__('module-news-comments::messages.deleted_label'))
            ->assertDontSee('This will be removed');
    }

    public function test_hidden_reference_does_not_expose_original_author_or_comment(): void
    {
        $news = $this->article();
        $author = User::factory()->create(['name' => 'HiddenName']);
        $replier = User::factory()->create();
        $admin = \App\Models\Admin::factory()->administrator()->create();

        $this->actingAs($author);
        $original = app(NewsCommentService::class)->submit($news, $author, 'Hidden comment text');

        $this->actingAs($replier);
        app(NewsCommentService::class)->submit($news, $replier, 'Visible reply', $original->id);

        $this->actingAs($admin, 'admin');
        app(\KaevCMS\Modules\NewsComments\Services\NewsCommentModerationService::class)
            ->act($admin, $original->id, 'hide');

        $this->actingAs($replier);
        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->assertSee('Visible reply')
            ->assertSee(__('module-news-comments::messages.reply_target_unavailable'))
            ->assertDontSee('HiddenName')
            ->assertDontSee('Hidden comment text');
    }

    private function article(): News
    {
        return News::query()->forceCreate([
            'title' => 'Player news article',
            'slug' => 'comments-player-'.uniqid(),
            'body' => '<p>News body</p>',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
    }
}
