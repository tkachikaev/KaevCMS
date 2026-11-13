<?php

namespace KaevCMS\Modules\NewsComments\Services;

use App\Models\ModuleState;
use App\Models\News;
use App\Models\User;
use App\Services\RegistrationSettings;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\NewsComments\Models\NewsComment;
use KaevCMS\Modules\NewsComments\Models\NewsCommentSetting;

final class NewsCommentService
{
    public function __construct(
        private readonly NewsCommentSettings $settings,
        private readonly RegistrationSettings $registration,
        private readonly ModuleManager $modules,
    ) {}

    public function submit(News $article, User $author, string $body, ?int $parentId = null): NewsComment
    {
        $this->assertAuthenticatedAuthor($author);

        return DB::transaction(function () use ($article, $author, $body, $parentId): NewsComment {
            [$news, $user, $configuration] = $this->lockContext($article->id, $author);
            $text = $this->validateBody($body, (int) $configuration['max_comment_length']);

            if ($parentId !== null) {
                $parent = NewsComment::query()->whereKey($parentId)
                    ->where('news_id', $news->id)
                    ->lockForUpdate()
                    ->first();
                if ($parent === null || $parent->status !== 'approved'
                    || $parent->deleted_by_author_at !== null
                    || $parent->deleted_by_admin_at !== null) {
                    $this->reject('invalid_parent');
                }
            }

            // Suppress rapid identical double-submits inside the user/news lock,
            // even when the owner configured a zero-second posting interval.
            $duplicate = NewsComment::query()
                ->where('news_id', $news->id)
                ->where('user_id', $user->id)
                ->where('parent_id', $parentId)
                ->where('created_at', '>=', now()->subSeconds(10))
                ->get(['body'])
                ->contains(static fn (NewsComment $existing): bool => $existing->body === $text);
            if ($duplicate) {
                $this->reject('duplicate');
            }

            $recent = DB::table('module_news_comment_submission_events')
                ->where('user_id', $user->id)
                ->where('created_at', '>=', now()->subHours(24));

            if ((clone $recent)->count() >= (int) $configuration['max_comments_per_day']) {
                $this->reject('daily_limit');
            }
            if ((clone $recent)->where('news_id', $news->id)->count()
                >= (int) $configuration['max_comments_per_news_per_day']) {
                $this->reject('article_limit');
            }

            $last = DB::table('module_news_comment_submission_events')
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->value('created_at');
            $interval = (int) $configuration['min_submission_interval_seconds'];
            if ($interval > 0 && $last !== null
                && Carbon::parse((string) $last)->addSeconds($interval)->isFuture()) {
                $this->reject('too_fast');
            }

            $status = $configuration['publication_mode'] === NewsCommentSettings::MODE_AUTOMATIC
                ? 'approved' : 'pending';

            $comment = NewsComment::query()->create([
                'news_id' => $news->id,
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'body' => $text,
                'status' => $status,
            ]);

            DB::table('module_news_comment_submission_events')->insert([
                'news_id' => $news->id,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);

            return $comment;
        }, 3);
    }

    /** @return 'approved'|'pending_edit' */
    public function edit(News $commentNews, User $author, int $commentId, string $body): string
    {
        $this->assertAuthenticatedAuthor($author);

        return DB::transaction(function () use ($commentNews, $author, $commentId, $body): string {
            [$news, $user, $configuration] = $this->lockContext($commentNews->id, $author);
            if ($configuration['allow_user_editing'] !== true) {
                $this->reject('editing_disabled');
            }

            $comment = NewsComment::query()->whereKey($commentId)->lockForUpdate()->firstOrFail();
            $this->assertOwner($comment, $news, $user);

            if ($comment->status !== 'approved' || $comment->pending_body !== null
                || $comment->deleted_by_author_at !== null
                || $comment->deleted_by_admin_at !== null) {
                $this->reject('cannot_edit');
            }

            $text = $this->validateBody($body, (int) $configuration['max_comment_length']);
            if ($text === $comment->body) {
                $this->reject('unchanged');
            }

            if ($configuration['publication_mode'] === NewsCommentSettings::MODE_AUTOMATIC) {
                if ($comment->last_edit_submitted_at !== null
                    && $comment->last_edit_submitted_at->copy()->addMinutes(5)->isFuture()) {
                    $this->reject('edit_wait');
                }

                $comment->forceFill([
                    'body' => $text,
                    'edited_at' => now(),
                    'last_edit_submitted_at' => now(),
                ])->save();

                return 'approved';
            }

            $comment->forceFill([
                'pending_body' => $text,
                'pending_revision_token' => (string) Str::uuid(),
                'pending_edited_at' => now(),
                'last_edit_submitted_at' => now(),
            ])->save();

            return 'pending_edit';
        }, 3);
    }

    public function deleteOwn(News $commentNews, User $author, int $commentId): void
    {
        $this->assertAuthenticatedAuthor($author);

        DB::transaction(function () use ($commentNews, $author, $commentId): void {
            [$news, $user] = $this->lockContext($commentNews->id, $author);
            $comment = NewsComment::query()->whereKey($commentId)->lockForUpdate()->firstOrFail();
            $this->assertOwner($comment, $news, $user);

            if ($comment->deleted_by_author_at !== null || $comment->deleted_by_admin_at !== null) {
                $this->reject('already_deleted');
            }

            if (! $comment->replies()->exists()) {
                $comment->delete();

                return;
            }

            $comment->forceFill([
                'body' => null,
                'pending_body' => null,
                'pending_revision_token' => null,
                'pending_edited_at' => null,
                'deleted_by_author_at' => now(),
            ])->save();
        }, 3);
    }

    /**
     * Locks are taken in the same order for every player mutation:
     * module state, news, user, then comment rows. A user row lock serializes
     * their new submissions across all news, including quota accounting.
     *
     * @return array{News, User, array<string, bool|int|string>}
     */
    private function lockContext(int $newsId, User $actor): array
    {
        $module = ModuleState::query()->whereKey('news-comments')->lockForUpdate()->first();
        abort_unless($module !== null && $module->enabled
            && $this->modules->inspect('news-comments')['status'] === 'enabled', 404);

        $news = News::query()->whereKey($newsId)->lockForUpdate()->firstOrFail();
        abort_unless($news->isLive(), 404);

        $user = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        abort_unless($user->is_active, 403);
        abort_if($this->registration->emailVerificationRequired() && ! $user->hasVerifiedEmail(), 403);

        // Serialize with owner settings updates so disabled comments take effect
        // before any newly committed player mutation.
        abort_unless(NewsCommentSetting::query()->whereKey(1)->lockForUpdate()->first() !== null, 404);

        $configuration = $this->settings->values();
        abort_unless($configuration !== null && $this->settings->enabledForNews($news), 404);

        return [$news, $user, $configuration];
    }

    private function assertAuthenticatedAuthor(User $user): void
    {
        abort_unless(auth('web')->id() === $user->getAuthIdentifier(), 403);
    }

    private function assertOwner(NewsComment $comment, News $news, User $user): void
    {
        abort_unless($comment->news_id === $news->id && $comment->user_id === $user->id, 404);
    }

    private function validateBody(string $body, int $maximum): string
    {
        $text = trim($body);
        if ($text === '' || mb_strlen($text) > $maximum) {
            $this->reject('invalid_body');
        }

        return $text;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages([
            'comment' => __('module-news-comments::messages.error_'.$message),
        ]);
    }
}
