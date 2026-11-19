<?php

namespace KaevCMS\Modules\Forum\Services;

use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Forum\Models\ForumSection;

final class ForumPermissions
{
    public const VIEWS = ['all', 'registered', 'moderators'];

    public const WRITES = ['registered', 'moderators', 'owner', 'nobody'];

    /** @var array<int, bool> */
    private array $moderators = [];

    public function visibleTo(ForumActor $actor, string $viewRule, string $globalVisibility = 'all'): bool
    {
        if ($globalVisibility === 'registered' && $actor->isGuest()) {
            return false;
        }

        return match ($viewRule) {
            'all' => true,
            'registered' => ! $actor->isGuest(),
            'moderators' => $this->isModerator($actor),
            default => false,
        };
    }

    public function canView(ForumActor $actor, ForumSection $section, string $globalVisibility = 'all'): bool
    {
        return $this->visibleTo($actor, $section->view_rule, $globalVisibility);
    }

    public function canCreateTopic(ForumActor $actor, ForumSection $section, string $globalVisibility = 'all'): bool
    {
        return $this->canView($actor, $section, $globalVisibility)
            && $this->canWrite($actor, $section->topic_rule);
    }

    public function canReply(ForumActor $actor, ForumSection $section, string $globalVisibility = 'all'): bool
    {
        return $this->canView($actor, $section, $globalVisibility)
            && $this->canWrite($actor, $section->reply_rule);
    }

    public function canWrite(ForumActor $actor, string $rule): bool
    {
        return match ($rule) {
            'registered' => ! $actor->isGuest(),
            'moderators' => $this->isModerator($actor),
            'owner' => $actor->isOwner(),
            'nobody' => false,
            default => false,
        };
    }

    public static function compatible(string $view, string $topics, string $replies): bool
    {
        if (! in_array($view, self::VIEWS, true)
            || ! in_array($topics, self::WRITES, true)
            || ! in_array($replies, self::WRITES, true)) {
            return false;
        }

        // A moderator-only area must not allow all registered accounts to write.
        return $view !== 'moderators' || ($topics !== 'registered' && $replies !== 'registered');
    }

    private function isModerator(ForumActor $actor): bool
    {
        if ($actor->isOwner()) {
            return true;
        }
        if ($actor->mode !== 'user' || $actor->user === null) {
            return false;
        }

        $id = (int) $actor->user->getKey();

        return $this->moderators[$id] ??= DB::table('module_forum_moderators')
            ->where('user_id', $id)
            ->exists();
    }
}
