<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\Forum\Models\ForumTopic;

/**
 * The "latest activity" table on the forum home: latest messages, new topics, topics
 * most discussed this week and the viewer's own topics. Lists only cover sections the
 * viewer can already see, skip hidden and deleted topics, and the shared lists are
 * cached for a minute per set of visible sections, so the home page stays cheap.
 */
final class ForumActivity
{
    public const MAX_LIMIT = 30;

    public const DEFAULT_LIMIT = 10;

    private const CACHE_SECONDS = 60;

    private const VERSION_KEY = 'forum.activity.version';

    /** Drops every cached list at once, e.g. right after a topic is hidden or moved. */
    public static function forget(): void
    {
        Cache::forever(self::VERSION_KEY, bin2hex(random_bytes(8)));
    }

    /** @return array{enabled: bool, limit: int} */
    public function settings(): array
    {
        $row = Schema::hasColumn('module_forum_settings', 'activity_limit')
            ? DB::table('module_forum_settings')->where('id', 1)->first()
            : null;
        $limit = (int) ($row->activity_limit ?? self::DEFAULT_LIMIT);

        return [
            'enabled' => (bool) ($row->activity_enabled ?? true) && $limit > 0,
            'limit' => max(1, min(self::MAX_LIMIT, $limit > 0 ? $limit : self::DEFAULT_LIMIT)),
        ];
    }

    /** Topics per tab on the forum home; 0 when the table is switched off. */
    public function limit(): int
    {
        $settings = $this->settings();

        return $settings['enabled'] ? $settings['limit'] : 0;
    }

    /**
     * @param  list<int>  $sectionIds  sections the viewer can see
     * @return array<string, Collection<int, ForumTopic>>
     */
    public function tabs(array $sectionIds, ?User $player, int $limit): array
    {
        sort($sectionIds);
        if ($limit < 1 || $sectionIds === []) {
            return [];
        }
        $version = (string) Cache::get(self::VERSION_KEY, '0');
        $cacheKey = 'forum.activity.'.md5($version.'|'.implode(',', $sectionIds).'|'.$limit);
        $visible = static fn () => ForumTopic::query()->with('section')
            ->whereIn('section_id', $sectionIds)->whereNull('hidden_at');

        $shared = Cache::remember($cacheKey, self::CACHE_SECONDS, static function () use ($visible, $sectionIds, $limit): array {
            $popularIds = DB::table('module_forum_posts as posts')
                ->join('module_forum_topics as topics', 'topics.id', '=', 'posts.topic_id')
                ->whereIn('topics.section_id', $sectionIds)
                ->whereNull('topics.hidden_at')->whereNull('topics.deleted_at')
                ->whereNull('posts.deleted_at')
                ->where('posts.created_at', '>=', now()->subDays(7))
                ->groupBy('posts.topic_id')
                ->orderByRaw('count(*) desc')->orderByRaw('max(posts.id) desc')
                ->limit($limit)
                ->pluck('posts.topic_id')->map(static fn ($id): int => (int) $id)->all();
            $popular = $visible()->whereIn('id', $popularIds)->get()
                ->sortBy(static fn (ForumTopic $topic): int|false => array_search((int) $topic->id, $popularIds, true))->values();

            return [
                'latest' => $visible()->orderByDesc('last_post_at')->orderByDesc('id')->limit($limit)->get(),
                'new' => $visible()->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get(),
                'popular' => $popular,
            ];
        });

        if ($player !== null) {
            $shared['mine'] = $visible()->where('author_type', 'user')->where('author_user_id', $player->id)
                ->orderByDesc('last_post_at')->orderByDesc('id')->limit($limit)->get();
        }

        return $shared;
    }
}
