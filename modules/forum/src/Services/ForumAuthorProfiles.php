<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Models\Admin;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumTopic;

/**
 * Author cards for the posts on one topic page: the current public name, role, account
 * avatar and forum statistics. The name and role stored with a post are kept only as a
 * fallback for accounts that no longer exist.
 */
final class ForumAuthorProfiles
{
    /**
     * @param  Collection<int, ForumPost>  $posts
     * @return array<int, array{name: string, role: string|null, user: User|null, posts: int, joined_at: CarbonInterface|null, is_topic_author: bool}>
     */
    public function forPosts(Collection $posts, ForumTopic $topic): array
    {
        $userIds = $posts->pluck('author_user_id')->filter()->unique()->values()->all();
        $adminIds = $posts->pluck('author_admin_id')->filter()->unique()->values()->all();

        $users = $userIds === [] ? collect() : User::query()->whereKey($userIds)->get()->keyBy('id');
        $admins = $adminIds === [] ? collect() : Admin::query()->whereKey($adminIds)->get(['id', 'name', 'created_at'])->keyBy('id');
        $userPosts = $this->postCounts('author_user_id', $userIds);
        $adminPosts = $this->postCounts('author_admin_id', $adminIds);
        $moderatorIds = $userIds === [] ? [] : array_flip(DB::table('module_forum_moderators')
            ->whereIn('user_id', $userIds)->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all());

        $profiles = [];
        foreach ($posts as $post) {
            $user = $post->author_user_id !== null ? $users->get($post->author_user_id) : null;
            $admin = $post->author_admin_id !== null ? $admins->get($post->author_admin_id) : null;

            $name = match (true) {
                $user instanceof User => $user->publicName(),
                $admin instanceof Admin => (string) $admin->name,
                default => (string) $post->getAttribute('author_name'),
            };

            $profiles[(int) $post->id] = [
                'name' => $name,
                'role' => match (true) {
                    $admin instanceof Admin => 'administrator',
                    $user instanceof User => isset($moderatorIds[(int) $user->id]) ? 'moderator' : null,
                    default => $post->author_role,
                },
                'user' => $user instanceof User ? $user : null,
                'posts' => match (true) {
                    $user instanceof User => (int) ($userPosts[$user->id] ?? 0),
                    $admin instanceof Admin => (int) ($adminPosts[$admin->id] ?? 0),
                    default => 0,
                },
                'joined_at' => $user instanceof User ? $user->created_at : ($admin instanceof Admin ? $admin->created_at : null),
                'is_topic_author' => $post->author_type === $topic->author_type
                    && $post->author_user_id === $topic->author_user_id
                    && $post->author_admin_id === $topic->author_admin_id,
            ];
        }

        return $profiles;
    }

    /**
     * Visible posts per author; removed and deleted posts are not counted.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function postCounts(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ForumPost::query()
            ->whereIn($column, $ids)
            ->whereNull('removed_at')
            ->selectRaw($column.' as author_id, count(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', 'author_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }
}
