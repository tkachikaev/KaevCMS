<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Models\User;
use App\Services\Notifications\PlayerNotificationCenter;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;

/**
 * Forum events for the player's bell: a reply in your topic, a quote of your post,
 * and a direct reply to your post. Each recipient gets at most one notification per
 * new message, never for their own message, and only if they can read the section.
 */
final class ForumNotifications
{
    public const PER_PAGE = 15;

    private const MAX_ADDRESSED = 10;

    public function __construct(
        private readonly PlayerNotificationCenter $center,
        private readonly ForumPermissions $permissions,
        private readonly ForumStructure $structure,
    ) {}

    /**
     * @param  list<int>  $quotedPostIds  posts quoted in the new message (from the reply form)
     * @param  list<int>  $repliedPostIds  posts answered with the "Reply" button
     */
    public function afterReply(ForumActor $actor, ForumTopic $topic, ForumPost $post, array $quotedPostIds, array $repliedPostIds): void
    {
        $section = ForumSection::query()->find($topic->section_id);
        if ($section === null || $topic->hidden_at !== null || $topic->trashed()) {
            return;
        }

        $authorId = $actor->user?->id;
        $name = (string) $actor->name();
        $title = Str::limit($topic->title, 80);
        $url = $this->postPath($topic, $post);

        /** @var array<int, array{type: string, key: string, group: string|null}> $recipients */
        $recipients = [];
        foreach ([['forum.reply', 'notify_reply', $repliedPostIds], ['forum.quote', 'notify_quote', $quotedPostIds]] as [$type, $key, $ids]) {
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))), 0, self::MAX_ADDRESSED);
            if ($ids === []) {
                continue;
            }
            $authors = ForumPost::query()
                ->where('topic_id', $topic->id)
                ->whereIn('id', $ids)
                ->where('id', '<', $post->id)
                ->where('author_type', 'user')
                ->whereNotNull('author_user_id')
                ->pluck('author_user_id');
            foreach ($authors as $userId) {
                $recipients[(int) $userId] ??= ['type' => $type, 'key' => $key, 'group' => null];
            }
        }

        if ($topic->author_type === 'user' && $topic->author_user_id !== null) {
            $recipients[(int) $topic->author_user_id] ??= [
                'type' => 'forum.topic_reply',
                'key' => 'notify_topic_reply',
                'group' => 'forum.topic.'.$topic->id,
            ];
        }
        unset($recipients[(int) $authorId]);
        if ($recipients === []) {
            return;
        }

        $visibility = $this->structure->globalVisibility();
        $users = User::query()->whereIn('id', array_keys($recipients))->where('is_active', true)->get();
        foreach ($users as $recipient) {
            if (! $this->permissions->canView(new ForumActor('user', user: $recipient), $section, $visibility)) {
                continue;
            }
            $event = $recipients[(int) $recipient->id];
            $this->center->notify(
                $recipient,
                $event['type'],
                'module-forum::messages.'.$event['key'],
                ['name' => $name, 'topic' => $title, 'actor_id' => (int) ($actor->user?->id ?? 0)],
                $url,
                $event['group'],
            );
        }
    }

    private function postPath(ForumTopic $topic, ForumPost $post): string
    {
        $position = ForumPost::query()->where('topic_id', $topic->id)->where('id', '<=', $post->id)->count();
        $page = intdiv(max(0, $position - 1), self::PER_PAGE) + 1;

        return '/forum/topics/'.$topic->id.'?page='.$page.'#post-'.$post->id;
    }
}
