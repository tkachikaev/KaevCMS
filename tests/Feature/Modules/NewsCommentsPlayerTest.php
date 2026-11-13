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

    public function test_authenticated_player_can_publish_comment_and_reply_one_level(): void
    {
        $news = $this->article();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->set('body', 'Great server!')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Great server!');

        $root = NewsComment::query()->firstOrFail();
        $this->assertSame('approved', $root->status);
        $this->assertNull($root->parent_id);

        Livewire::test(NewsThread::class, ['newsId' => $news->id])
            ->call('openReply', $root->id)
            ->set('replyBody', 'I agree')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertSee('I agree');

        $reply = NewsComment::query()->whereNotNull('parent_id')->firstOrFail();
        $this->assertSame($root->id, $reply->parent_id);
        $this->assertDatabaseCount('module_news_comment_submission_events', 2);

        $this->expectException(ValidationException::class);
        app(NewsCommentService::class)->submit($news, $user, 'A nested reply', $reply->id);
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
