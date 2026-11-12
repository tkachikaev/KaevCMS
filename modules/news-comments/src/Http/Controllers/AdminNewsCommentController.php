<?php

namespace KaevCMS\Modules\NewsComments\Http\Controllers;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\News;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KaevCMS\Modules\NewsComments\Http\Requests\UpdateNewsCommentSettingsRequest;
use KaevCMS\Modules\NewsComments\Models\NewsCommentNewsSetting;
use KaevCMS\Modules\NewsComments\Services\NewsCommentSettings;

final class AdminNewsCommentController extends Controller
{
    public function __construct(
        private readonly NewsCommentSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $values = $this->settings->values();
        $news = News::query()->with('translations')->latest('created_at')->paginate(10);
        $policies = $values === null ? collect() : DB::table('module_news_comment_news_settings')
            ->whereIn('news_id', $news->getCollection()->modelKeys())
            ->pluck('policy', 'news_id');

        return view('module-news-comments::admin.index', [
            'settings' => $values,
            'news' => $news,
            'policies' => $policies,
            'canManage' => $request->user('admin')?->role === AdminRole::Owner,
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
