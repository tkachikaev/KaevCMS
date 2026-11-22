<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;
use KaevCMS\Modules\Forum\Services\ForumPostChanges;

final class ForumPostController extends Controller
{
    public function __construct(
        private readonly ForumActorResolver $actors,
        private readonly ForumPostChanges $changes,
    ) {}

    public function edit(Request $request, ForumPost $post): Response
    {
        $actor = $this->actors->resolve($request);
        [$topic, $section] = $this->context($actor, $post);
        abort_unless($this->changes->canChange($actor, $post, $topic, $section), 403);

        return $this->page('module-forum::post-edit', $request, $actor, [
            'topic' => $topic,
            'section' => $section,
            'post' => $post,
        ]);
    }

    public function update(Request $request, ForumPost $post): RedirectResponse
    {
        $actor = $this->actors->resolve($request);
        [$topic, $section] = $this->context($actor, $post);
        abort_unless($this->changes->canChange($actor, $post, $topic, $section), 403);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:10000'],
            'body_html' => ['nullable', 'string', 'max:20000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);

        $this->changes->update($actor, (int) $post->id,
            (string) ($data['body'] ?? ''), $data['body_html'] ?? null, (int) $data['version']);

        return $this->postUrl($post);
    }

    public function destroy(Request $request, ForumPost $post): RedirectResponse
    {
        $actor = $this->actors->resolve($request);
        [$topic, $section] = $this->context($actor, $post);
        abort_unless($this->changes->canChange($actor, $post, $topic, $section), 403);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);

        $this->changes->remove($actor, (int) $post->id, (int) $data['version']);

        return $this->postUrl($post);
    }

    public function history(Request $request, ForumPost $post): Response
    {
        $actor = $this->actors->resolve($request);
        [$topic, $section] = $this->context($actor, $post);
        abort_unless($this->changes->canViewHistory($actor, $topic, $section), 403);

        // Never expose expired private revisions, even before a scheduled purge.
        $revisions = DB::table('module_forum_post_revisions')
            ->where('post_id', $post->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(15);

        return $this->page('module-forum::post-history', $request, $actor, [
            'topic' => $topic,
            'section' => $section,
            'post' => $post,
            'revisions' => $revisions,
        ]);
    }

    /** @return array{0: ForumTopic, 1: ForumSection} */
    private function context(ForumActor $actor, ForumPost $post): array
    {
        $topic = ForumTopic::query()->findOrFail($post->topic_id);
        $section = ForumSection::query()->findOrFail($topic->section_id);
        ForumCategory::query()->findOrFail($section->category_id);
        abort_unless($this->changes->canRead($actor, $topic, $section), 404);

        return [$topic, $section];
    }

    private function postUrl(ForumPost $post): RedirectResponse
    {
        $position = ForumPost::query()
            ->where('topic_id', $post->topic_id)->where('id', '<=', $post->id)->count();
        $page = intdiv(max(0, $position - 1), 15) + 1;

        return redirect()->to(route('modules.forum.topics.show', $post->topic_id)
            .'?page='.$page.'#post-'.$post->id);
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
