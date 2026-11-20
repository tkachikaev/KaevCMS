<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use KaevCMS\Modules\Forum\Services\ForumStructure;

final class ForumSearchController extends Controller
{
    public function __construct(
        private readonly ForumActorResolver $actors,
        private readonly ForumPermissions $permissions,
        private readonly ForumStructure $structure,
    ) {}

    public function __invoke(Request $request): Response
    {
        $actor = $this->actors->resolve($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $term = trim((string) ($data['q'] ?? ''));

        if ($term !== '' && mb_strlen($term) < 2) {
            throw ValidationException::withMessages([
                'q' => __('module-forum::messages.search_min_length'),
            ]);
        }

        $results = null;
        $rules = $this->permissions->allowedViewRules($actor, $this->structure->globalVisibility());

        if ($term !== '' && $rules !== []) {
            // ! is our SQL LIKE escape character; literal %, _ and ! in a
            // visitor's query may not expand the scope of the search.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
            $like = '%'.$escaped.'%';

            $results = ForumTopic::query()
                ->whereNull('hidden_at')
                ->whereHas('section', static fn (Builder $section) => $section
                    ->whereIn('view_rule', $rules)
                    ->whereHas('category'))
                ->where(static fn (Builder $q) => $q
                    ->whereRaw("module_forum_topics.title LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('posts', static fn (Builder $posts) => $posts
                        ->whereNull('removed_at')
                        ->whereRaw("module_forum_posts.body_text LIKE ? ESCAPE '!'", [$like])))
                ->with('section')
                ->orderByDesc('last_post_at')->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();
        }

        return response()->view('module-forum::search', [
            'actor' => $actor,
            'identities' => $this->actors->identities($request),
            'term' => $term,
            'results' => $results,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
