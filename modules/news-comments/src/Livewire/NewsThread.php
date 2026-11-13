<?php

namespace KaevCMS\Modules\NewsComments\Livewire;

use App\Models\ModuleState;
use App\Models\News;
use App\Models\User;
use App\Services\RegistrationSettings;
use App\Support\Modules\ModuleManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\NewsComments\Models\NewsComment;
use KaevCMS\Modules\NewsComments\Services\NewsCommentService;
use KaevCMS\Modules\NewsComments\Services\NewsCommentSettings;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class NewsThread extends Component
{
    use WithPagination;

    #[Locked]
    public int $newsId;

    #[Locked]
    public int $visibleReplies = 20;

    public string $body = '';

    public string $replyBody = '';

    public ?int $replyToId = null;

    public ?int $editingId = null;

    public string $editingBody = '';

    public ?string $notice = null;

    public function mount(int $newsId): void
    {
        $this->newsId = $newsId;
        $this->article();
    }

    public function hydrate(): void
    {
        $this->article();
    }

    public function send(NewsCommentService $comments): void
    {
        $news = $this->article();
        $user = $this->author();
        $text = trim($this->body);
        $this->validate(['body' => ['required', 'string', 'max:'.$this->maximumLength()]]);

        $created = null;
        if (! $this->tryAction(function () use ($comments, $news, $user, $text, &$created): void {
            $created = $comments->submit($news, $user, $text);
        }, 'body')) {
            return;
        }

        $this->body = '';
        $this->notice = $this->sendNotice($created?->status);
        $this->resetPage('commentsPage');
    }

    public function openReply(int $commentId): void
    {
        $this->article();
        $this->author();

        $parent = NewsComment::query()->whereKey($commentId)
            ->where('news_id', $this->newsId)
            ->whereNull('parent_id')
            ->where('status', 'approved')
            ->whereNull('deleted_by_author_at')
            ->firstOrFail();

        $this->resetValidation();
        $this->replyBody = '';
        $this->replyToId = (int) $parent->id;
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
        $this->replyBody = '';
        $this->resetValidation();
    }

    public function sendReply(NewsCommentService $comments): void
    {
        $news = $this->article();
        $user = $this->author();
        $this->replyBody = trim($this->replyBody);
        $this->validate(['replyBody' => ['required', 'string', 'max:'.$this->maximumLength()]]);
        abort_unless($this->replyToId !== null, 422);

        $created = null;
        if (! $this->tryAction(
            function () use ($comments, $news, $user, &$created): void {
                $created = $comments->submit($news, $user, $this->replyBody, $this->replyToId);
            },
            'replyBody',
        )) {
            return;
        }

        $this->cancelReply();
        $this->notice = $this->sendNotice($created?->status);
    }

    public function openEdit(int $commentId): void
    {
        $news = $this->article();
        $user = $this->author();
        $settings = app(NewsCommentSettings::class)->values();
        abort_unless($settings !== null && $settings['allow_user_editing'] === true, 403);

        $comment = NewsComment::query()->findOrFail($commentId);
        abort_unless($comment->news_id === $news->id && $comment->user_id === $user->id, 404);
        abort_unless($comment->status === 'approved' && $comment->pending_body === null
            && $comment->deleted_by_author_at === null, 403);

        $this->cancelReply();
        $this->resetValidation();
        $this->editingId = (int) $comment->id;
        $this->editingBody = (string) $comment->body;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editingBody = '';
        $this->resetValidation();
    }

    public function saveEdit(NewsCommentService $comments): void
    {
        $news = $this->article();
        $user = $this->author();
        $this->editingBody = trim($this->editingBody);
        $this->validate(['editingBody' => ['required', 'string', 'max:'.$this->maximumLength()]]);

        $result = null;
        if (! $this->tryAction(
            function () use ($comments, $news, $user, &$result): void {
                $result = $comments->edit($news, $user, (int) $this->editingId, $this->editingBody);
            },
            'editingBody',
        )) {
            return;
        }

        $this->cancelEdit();
        $this->notice = $result === 'approved'
            ? __('module-news-comments::messages.edit_published')
            : __('module-news-comments::messages.edit_pending');
    }

    public function deleteOwn(int $commentId, NewsCommentService $comments): void
    {
        $news = $this->article();
        $user = $this->author();

        if (! $this->tryAction(fn () => $comments->deleteOwn($news, $user, $commentId), 'action')) {
            return;
        }

        $this->cancelReply();
        $this->cancelEdit();
        $this->notice = __('module-news-comments::messages.deleted_notice');
    }

    public function moreReplies(): void
    {
        $this->article();
        $this->visibleReplies = min(1000, $this->visibleReplies + 20);
    }

    public function render(): View
    {
        $news = $this->article();
        $viewer = auth('web')->user();
        $viewerId = $viewer instanceof User && $viewer->is_active ? (int) $viewer->id : null;
        $settings = app(NewsCommentSettings::class)->values();
        abort_unless($settings !== null, 404);

        $visible = static function (Builder $query) use ($viewerId): void {
            $query->where(function (Builder $states) use ($viewerId): void {
                $states->where('status', 'approved');
                if ($viewerId !== null) {
                    $states->orWhere(static fn (Builder $own): Builder => $own
                        ->where('status', 'pending')
                        ->where('user_id', $viewerId));
                }
            });
        };

        $roots = NewsComment::query()
            ->where('news_id', $news->id)
            ->whereNull('parent_id')
            ->where($visible)
            ->with('author')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'commentsPage');

        $roots->getCollection()->load([
            'replies' => static fn (Builder $query) => $query
                ->where($visible)
                ->with('author')
                ->orderBy('id')
                ->limit($this->visibleReplies),
        ]);
        $roots->getCollection()->loadCount([
            'replies as visible_replies_count' => $visible,
        ]);

        $view = 'module-news-comments::livewire.news-thread';

        return view($view, [
            'news' => $news,
            'comments' => $roots,
            'viewerId' => $viewerId,
            'canWrite' => $this->canWrite($viewer),
            'canEdit' => $settings['allow_user_editing'] === true,
            'maximumLength' => (int) $settings['max_comment_length'],
        ]);
    }

    private function article(): News
    {
        $module = ModuleState::query()->find('news-comments');
        abort_unless($module !== null && $module->enabled
            && app(ModuleManager::class)->inspect('news-comments')['status'] === 'enabled', 404);

        $news = News::query()->findOrFail($this->newsId);
        abort_unless(app(NewsCommentSettings::class)->enabledForNews($news), 404);

        return $news;
    }

    private function canWrite(mixed $viewer): bool
    {
        return $viewer instanceof User
            && $viewer->is_active
            && (! app(RegistrationSettings::class)->emailVerificationRequired()
                || $viewer->hasVerifiedEmail());
    }

    private function author(): User
    {
        $viewer = auth('web')->user();
        abort_unless($viewer instanceof User, 401);
        $viewer->refresh();
        abort_unless($this->canWrite($viewer), 403);

        return $viewer;
    }

    private function maximumLength(): int
    {
        $settings = app(NewsCommentSettings::class)->values();
        abort_unless($settings !== null, 404);

        return (int) $settings['max_comment_length'];
    }

    private function sendNotice(?string $status): string
    {
        return $status === 'approved'
            ? __('module-news-comments::messages.published_notice')
            : __('module-news-comments::messages.pending_notice');
    }

    /** @param \Closure(): mixed $action */
    private function tryAction(\Closure $action, string $field): bool
    {
        $this->resetValidation();

        try {
            $action();

            return true;
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->addError($field, (string) ($errors['comment'][0] ?? __('module-news-comments::messages.error_invalid_body')));

            return false;
        }
    }
}
