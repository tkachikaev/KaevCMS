<?php

namespace KaevCMS\Modules\NewsComments\Http\Controllers;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\News;
use Illuminate\Database\Eloquent\Builder;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KaevCMS\Modules\NewsComments\Http\Requests\UpdateNewsCommentSettingsRequest;
use KaevCMS\Modules\NewsComments\Models\NewsComment;
use KaevCMS\Modules\NewsComments\Models\NewsCommentNewsSetting;
use KaevCMS\Modules\NewsComments\Services\NewsCommentModerationService;
use KaevCMS\Modules\NewsComments\Services\NewsCommentSettings;

final class AdminNewsCommentController extends Controller
{
    public function __construct(
        private readonly NewsCommentSettings $settings,
        private readonly NewsCommentModerationService $moderation,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $filters = $request->validate([
            'review' => ['nullable', Rule::in([
                'pending', 'new', 'edits', 'approved', 'rejected', 'hidden', 'all',
            ])],
            'news_id' => ['nullable', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $state = (string) ($filters['review'] ?? 'pending');
        $newsId = isset($filters['news_id']) ? (int) $filters['news_id'] : null;
        $authorId = isset($filters['author_id']) ? (int) $filters['author_id'] : null;

        $values = $this->settings->values();
        $news = News::query()->with('translations')->latest('created_at')->paginate(10);

        $review = null;
        if ($values !== null) {
            $query = NewsComment::query()->with(['author', 'news']);

            if ($newsId !== null) {
                $query->where('news_id', $newsId);
            }
            if ($authorId !== null) {
                $query->where('user_id', $authorId);
            }

            switch ($state) {
                case 'pending':
                    $query->whereNull('deleted_by_author_at')
                        ->where(static function (Builder $pending): void {
                            $pending->where('status', 'pending')
                                ->orWhere(static function (Builder $edits): void {
                                    $edits->where('status', 'approved')->whereNotNull('pending_body');
                                });
                        });
                    break;
                case 'new':
                    $query->whereNull('deleted_by_author_at')->where('status', 'pending');
                    break;
                case 'edits':
                    $query->whereNull('deleted_by_author_at')->where('status', 'approved')
                        ->whereNotNull('pending_body');
                    break;
                case 'approved':
                case 'rejected':
                case 'hidden':
                    $query->where('status', $state);
                    break;
                default:
                    break;
            }

            $review = $query->orderByDesc('id')->paginate(12, ['*'], 'reviewPage')->withQueryString();
        }
        $policies = $values === null ? collect() : DB::table('module_news_comment_news_settings')
            ->whereIn('news_id', $news->getCollection()->modelKeys())
            ->pluck('policy', 'news_id');

        return view('module-news-comments::admin.index', [
            'settings' => $values,
            'news' => $news,
            'policies' => $policies,
            'canManage' => $admin->role === AdminRole::Owner,
            'canModerate' => $this->moderation->canModerate($admin),
            'canDelete' => $this->moderation->canDelete($admin),
            'pendingCount' => $values !== null ? $this->moderation->pendingCount() : 0,
            'reviewItems' => $review,
            'activeReview' => $state,
            'reviewNewsId' => $newsId,
            'reviewAuthorId' => $authorId,
            'adminPath' => (string) $request->route('adminPath'),
        ]);
    }

    public function updateSettings(UpdateNewsCommentSettingsRequest $request): RedirectResponse
    {
        $admin = $this->owner($request);
        $data = $request->validated();
        foreach (NewsCommentSettings::LIMITS as $field => $range) {
            $data[$field] = (int) $data[$field];
        }

        $this->settings->update($data, $admin);
        $this->audit->success(
            category: 'module',
            action: 'news_comments.settings_updated',
            actor: $admin,
            target: 'news-comments',
            details: $data,
        );

        return $this->back($request)->with('status', __('module-news-comments::messages.saved'));
    }

    public function updateNews(Request $request, News $news): RedirectResponse
    {
        $admin = $this->owner($request);
        abort_if($this->settings->values() === null, 503);

        $data = $request->validate([
            'policy' => ['required', Rule::in(['inherit', 'enabled', 'disabled'])],
        ]);
        $policy = $data['policy'];

        DB::transaction(static function () use ($news, $policy, $admin): void {
            // Same article lock order as player submissions and edits.
            News::query()->whereKey($news->getKey())->lockForUpdate()->firstOrFail();

            if ($policy === 'inherit') {
                DB::table('module_news_comment_news_settings')->where('news_id', $news->getKey())->delete();

                return;
            }

            NewsCommentNewsSetting::query()->updateOrCreate(
                ['news_id' => $news->getKey()],
                ['policy' => $policy, 'updated_by_admin_id' => $admin->getKey()],
            );
        });

        $this->audit->success(
            category: 'module',
            action: 'news_comments.news_policy_updated',
            actor: $admin,
            target: $news,
            details: ['policy' => $policy],
        );

        return $this->back($request)->with('status', __('module-news-comments::messages.saved'));
    }

    public function approve(Request $request, int $comment): RedirectResponse
    {
        return $this->moderate($request, $comment, NewsCommentModerationService::ACTION_APPROVE);
    }

    public function reject(Request $request, int $comment): RedirectResponse
    {
        return $this->moderate($request, $comment, NewsCommentModerationService::ACTION_REJECT);
    }

    public function approveEdit(Request $request, int $comment): RedirectResponse
    {
        return $this->moderate($request, $comment, NewsCommentModerationService::ACTION_APPROVE_EDIT);
    }

    public function rejectEdit(Request $request, int $comment): RedirectResponse
    {
        return $this->moderate($request, $comment, NewsCommentModerationService::ACTION_REJECT_EDIT);
    }

    public function hide(Request $request, int $comment): RedirectResponse
    {
        return $this->moderate($request, $comment, NewsCommentModerationService::ACTION_HIDE);
    }

    public function restore(Request $request, int $comment): RedirectResponse
    {
        return $this->moderate($request, $comment, NewsCommentModerationService::ACTION_RESTORE);
    }

    public function destroy(Request $request, int $comment): RedirectResponse
    {
        return $this->moderate($request, $comment, NewsCommentModerationService::ACTION_DESTROY);
    }

    private function moderate(Request $request, int $comment, string $action): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $revisionHash = $request->input('expected_revision_hash');
        $this->moderation->act($admin, $comment, $action, is_string($revisionHash) ? $revisionHash : null);

        return redirect()->route('admin.module-pages.news-comments.index', [
            'adminPath' => $request->route('adminPath'),
            'review' => in_array($request->query('review'), [
                'pending', 'new', 'edits', 'approved', 'rejected', 'hidden', 'all',
            ], true) ? $request->query('review') : 'pending',
            'reviewPage' => max(1, $request->integer('reviewPage', 1)),
        ])->with('status', __('module-news-comments::messages.moderation_saved'));
    }

    private function owner(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->role === AdminRole::Owner, 403);

        return $admin;
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect()->route('admin.module-pages.news-comments.index', [
            'adminPath' => $request->route('adminPath'),
            'page' => max(1, $request->integer('page', 1)),
        ]);
    }
}
