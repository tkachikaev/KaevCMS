<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use KaevCMS\Modules\Forum\Services\ForumPostChanges;
use KaevCMS\Modules\Forum\Services\ForumStructure;

final class ForumSearchController extends Controller
{
    private const MIN_LENGTH = 3;

    private const PER_PAGE = 20;

    private const MAX_RESULTS = 400;

    private const CACHE_SECONDS = 120;

    private const GLOBAL_PER_MINUTE = 120;

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

        if ($term !== '' && mb_strlen($term) < self::MIN_LENGTH) {
            throw ValidationException::withMessages([
                'q' => __('module-forum::messages.search_min_length'),
            ]);
        }

        $results = null;
        $busy = false;
        $rules = $this->permissions->allowedViewRules($actor, $this->structure->globalVisibility());

        if ($term !== '' && $rules !== []) {
            // Text search scans message bodies: the same query is answered from a short
            // cache, and the whole forum gets a shared budget of fresh searches per minute,
            // so many addresses together cannot keep the database busy.
            sort($rules);
            $cacheKey = 'forum.search.'.md5(Cache::get(ForumPostChanges::SEARCH_VERSION, '0').'|'.implode(',', $rules).'|'.mb_strtolower($term));
            $ids = Cache::get($cacheKey);
            if (! is_array($ids)) {
                if (! RateLimiter::attempt('forum-search:global', self::GLOBAL_PER_MINUTE, static fn () => true, 60)) {
                    $busy = true;
                } else {
                    $ids = $this->matchingIds($term, $rules);
                    Cache::put($cacheKey, $ids, self::CACHE_SECONDS);
                }
            }

            if (is_array($ids)) {
                // Permissions may have changed since these candidates were cached.
                $visible = ForumTopic::query()->whereIntegerInRaw('id', $ids)->whereNull('hidden_at')
                    ->whereHas('section', static fn (Builder $section) => $section->whereIn('view_rule', $rules)->whereHas('category'));
                $ids = array_values(array_intersect($ids, (clone $visible)->pluck('id')->all()));
                $page = LengthAwarePaginator::resolveCurrentPage();
                abort_if($page > max(1, (int) ceil(count($ids) / self::PER_PAGE)), 404);
                $slice = array_slice($ids, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
                $topics = $visible->with('section')->whereIntegerInRaw('id', $slice)
                    ->get()
                    ->sortBy(static fn (ForumTopic $topic): int|false => array_search((int) $topic->id, $slice, true))->values();
                $results = (new LengthAwarePaginator($topics, count($ids), self::PER_PAGE, $page, [
                    'path' => $request->url(),
                    'pageName' => 'page',
                ]))->withQueryString();
            }
        }

        return response()->view('module-forum::search', [
            'actor' => $actor,
            'identities' => $this->actors->identities($request),
            'term' => $term,
            'results' => $results,
            'busy' => $busy,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    /**
     * @param  list<string>  $rules
     * @return list<int>
     */
    private function matchingIds(string $term, array $rules): array
    {
        // ! is our SQL LIKE escape character; literal %, _ and ! in a
        // visitor's query may not expand the scope of the search.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $like = '%'.$escaped.'%';

        return ForumTopic::query()
            ->whereNull('hidden_at')
            ->whereHas('section', static fn (Builder $section) => $section
                ->whereIn('view_rule', $rules)
                ->whereHas('category'))
            ->where(static fn (Builder $q) => $q
                ->whereRaw("module_forum_topics.title LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('posts', static fn (Builder $posts) => $posts
                    ->whereNull('removed_at')
                    ->whereRaw("module_forum_posts.body_text LIKE ? ESCAPE '!'", [$like])))
            ->orderByDesc('last_post_at')->orderByDesc('id')
            ->limit(self::MAX_RESULTS)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
