<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use KaevCMS\Modules\Forum\Services\ForumStructure;
use KaevCMS\Modules\Forum\Services\ForumTopicModeration;

final class ForumModerationController extends Controller
{
    public function __construct(
        private readonly ForumActorResolver $actors,
        private readonly ForumPermissions $permissions,
        private readonly ForumStructure $structure,
        private readonly ForumTopicModeration $moderation,
    ) {}

    public function index(Request $request, ForumSection $section): Response
    {
        $actor = $this->actors->resolve($request);
        $category = ForumCategory::query()->findOrFail($section->category_id);

        abort_unless($this->permissions->canView($actor, $section, $this->structure->globalVisibility()), 404);
        abort_unless($this->moderation->isStaff($actor), 403);

        $topics = $this->moderation->recoverableTopics($actor, $section)
            ->orderByDesc('last_post_at')->orderByDesc('id')
            ->paginate(20);

        return $this->page('module-forum::moderation', $request, $actor, [
            'section' => $section,
            'category' => $category,
            'topics' => $topics,
        ]);
    }

    public function action(Request $request, int $topic, string $action): RedirectResponse
    {
        $actor = $this->actors->resolve($request);
        abort_unless($this->moderation->isStaff($actor), 403);
        $result = $this->moderation->change($actor, $topic, $action);
        $targetRoute = $result['public']
            ? route('modules.forum.topics.show', $topic)
            : route('modules.forum.sections.moderation', $result['section_id']);

        return redirect()->to($targetRoute)
            ->with('forum_status', __('module-forum::messages.moderation_'.$action));
    }

    public function move(Request $request, int $topic): RedirectResponse
    {
        $actor = $this->actors->resolve($request);
        abort_unless($this->moderation->isStaff($actor), 403);
        $data = $request->validate([
            'section_id' => ['required', 'integer', 'min:1'],
            'confirm_exposure' => ['sometimes', 'boolean'],
        ]);

        $this->moderation->move($actor, $topic, (int) $data['section_id'],
            (bool) ($data['confirm_exposure'] ?? false));

        $current = ForumTopic::query()->findOrFail($topic);
        $target = $current->hidden_at === null
            ? route('modules.forum.topics.show', $topic)
            : route('modules.forum.sections.moderation', $current->section_id);

        return redirect()->to($target)
            ->with('forum_status', __('module-forum::messages.moderation_move'));
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, Request $request, ForumActor $actor, array $data): Response
    {
        return response()->view($view, $data + [
            'actor' => $actor,
            'identities' => $this->actors->identities($request),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
