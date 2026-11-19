<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use KaevCMS\Modules\Forum\Services\ForumPosting;
use KaevCMS\Modules\Forum\Services\ForumStructure;

final class ForumTopicController extends Controller
{
    public function __construct(
        private readonly ForumActorResolver $actors,
        private readonly ForumPermissions $permissions,
        private readonly ForumStructure $structure,
        private readonly ForumPosting $posting,
    ) {}

    public function section(Request $request, ForumSection $section): Response
    {
        $actor = $this->actors->resolve($request);
        $category = $this->readSection($actor, $section);
        $topics = ForumTopic::query()
            ->where('section_id', $section->id)
            ->whereNull('hidden_at')
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_post_at')
            ->orderByDesc('id')
            ->paginate(20);

        return $this->page('module-forum::section', $request, $actor, [
            'category' => $category,
            'section' => $section,
            'topics' => $topics,
            'canStart' => $this->posting->canStart($actor, $section),
        ]);
    }

    public function create(Request $request, ForumSection $section): Response
    {
        $actor = $this->actors->resolve($request);
        $category = $this->readSection($actor, $section);
        abort_unless($this->posting->canStart($actor, $section), 403);

        return $this->page('module-forum::topic-create', $request, $actor, [
            'category' => $category,
            'section' => $section,
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, ForumSection $section): RedirectResponse
    {
        $actor = $this->actors->resolve($request);
        $this->readSection($actor, $section);
        abort_unless($this->posting->canStart($actor, $section), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'submission_key' => ['required', 'uuid'],
        ]);
        $topic = $this->posting->createTopic($actor, (int) $section->id, $data['title'], $data['body'], $data['submission_key']);
        $this->audit('topic_created', $actor, 'topic #'.$topic->id);

        return redirect()->route('modules.forum.topics.show', $topic);
    }

    public function show(Request $request, ForumTopic $topic): Response
    {
        $actor = $this->actors->resolve($request);
        $section = $this->readTopic($actor, $topic);
        $posts = ForumPost::query()
            ->where('topic_id', $topic->id)
            ->orderBy('id')
            ->paginate(15);

        return $this->page('module-forum::topic', $request, $actor, [
            'category' => ForumCategory::query()->findOrFail($section->category_id),
            'section' => $section,
            'topic' => $topic,
            'posts' => $posts,
            'canReply' => $this->posting->canReply($actor, $topic, $section),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function reply(Request $request, ForumTopic $topic): RedirectResponse
    {
        $actor = $this->actors->resolve($request);
        $this->readTopic($actor, $topic);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'submission_key' => ['required', 'uuid'],
        ]);
        $post = $this->posting->addReply($actor, (int) $topic->id, $data['body'], $data['submission_key']);
        $this->audit('reply_created', $actor, 'post #'.$post->id);

        // Calculate the page from the persisted post order, not from an untrusted form field.
        $position = ForumPost::query()->where('topic_id', $topic->id)->where('id', '<=', $post->id)->count();
        $page = intdiv(max(0, $position - 1), 15) + 1;

        return redirect()->to(route('modules.forum.topics.show', $topic).'?page='.$page.'#post-'.$post->id);
    }

    private function readSection(ForumActor $actor, ForumSection $section): ForumCategory
    {
        $category = ForumCategory::query()->findOrFail($section->category_id);
        abort_unless($this->permissions->canView($actor, $section, $this->structure->globalVisibility()), 404);

        return $category;
    }

    private function readTopic(ForumActor $actor, ForumTopic $topic): ForumSection
    {
        abort_if($topic->hidden_at !== null, 404);
        $section = ForumSection::query()->findOrFail($topic->section_id);
        $this->readSection($actor, $section);

        return $section;
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, Request $request, ForumActor $actor, array $data): Response
    {
        return response()->view($view, $data + [
            'actor' => $actor,
            'identities' => $this->actors->identities($request),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    private function audit(string $action, ForumActor $actor, string $target): void
    {
        app(AuditLogger::class)->success(
            category: 'forum',
            action: 'forum.'.$action,
            actor: $actor->admin ?? $actor->user,
            target: $target,
        );
    }
}
