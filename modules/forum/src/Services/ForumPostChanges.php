<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;

final class ForumPostChanges
{
    public const SEARCH_VERSION = 'forum.search.version';

    /** @var array<int, bool> */
    private array $currentModerators = [];

    public function __construct(
        private readonly ForumPermissions $permissions,
        private readonly ForumStructure $structure,
        private readonly ForumPosting $posting,
        private readonly AuditLogger $audit,
    ) {}

    public function canChange(ForumActor $actor, ForumPost $post, ForumTopic $topic, ForumSection $section): bool
    {
        if ($actor->isGuest() || $post->removed_at !== null
            || ! $this->canRead($actor, $topic, $section)) {
            return false;
        }

        if ($actor->isOwner()) {
            return true;
        }

        $own = $this->isAuthor($actor, $post);
        if ($this->permissions->isModerator($actor)) {
            if ($own) {
                return true;
            }

            // Moderators cannot edit CMS staff or other moderator posts.
            return $post->author_type === 'user'
                && $post->author_role !== 'moderator'
                && $post->author_user_id !== null
                && ! $this->isCurrentModerator((int) $post->author_user_id);
        }

        // Authors may edit indefinitely, except in closed/hidden/deleted topics.
        return $own && $topic->status === 'open'
            && $topic->hidden_at === null && $topic->deleted_at === null;
    }

    public function canViewHistory(ForumActor $actor, ForumTopic $topic, ForumSection $section): bool
    {
        return ! $actor->isGuest()
            && $this->permissions->isModerator($actor)
            && $this->canRead($actor, $topic, $section);
    }

    public function canRead(ForumActor $actor, ForumTopic $topic, ForumSection $section): bool
    {
        return $topic->hidden_at === null && $topic->deleted_at === null
            && $section->deleted_at === null
            && $this->permissions->canView($actor, $section, $this->structure->globalVisibility());
    }

    public function update(ForumActor $actor, int $postId, string $plain, ?string $html, int $version): bool
    {
        $changed = DB::transaction(function () use ($actor, $postId, $plain, $html, $version): bool {
            // Same lock order as new topic/reply writes and structure changes.
            $this->posting->lockCurrentSettings();
            [$post, $topic, $section] = $this->lockPost($postId);
            if ($this->permissions->isModerator($actor)) {
                $this->permissions->assertCurrentModeratorForWrite($actor);
            }
            abort_unless($this->canChange($actor, $post, $topic, $section), 403);
            $this->assertVersion($post, $version);

            // Reuse the shared HTML sanitizer and the account-age link policy.
            [$text, $safeHtml] = $this->posting->prepareBody($actor, $plain, $html);
            if ($post->body_text === $text && $post->body_html === $safeHtml) {
                return false;
            }

            $this->posting->assertCurrentContentPolicy($actor, $text, $safeHtml);
            $this->snapshot($post, $actor, 'edited');
            $post->forceFill([
                'body_text' => $text,
                'body_html' => $safeHtml,
                'edited_at' => now(),
                'edit_version' => $post->edit_version + 1,
            ])->save();

            return true;
        }, 3);

        if ($changed) {
            $this->invalidateSearchAfterCommit();
            $this->audit->success('forum', 'forum.post_edited',
                actor: $actor->admin ?? $actor->user, target: 'post #'.$postId);
        }

        return $changed;
    }

    public function remove(ForumActor $actor, int $postId, int $version): void
    {
        DB::transaction(function () use ($actor, $postId, $version): void {
            [$post, $topic, $section] = $this->lockPost($postId);
            if ($this->permissions->isModerator($actor)) {
                $this->permissions->assertCurrentModeratorForWrite($actor);
            }
            abort_if($post->removed_at !== null, 409);
            abort_unless($this->canChange($actor, $post, $topic, $section), 403);
            $this->assertVersion($post, $version);

            $this->snapshot($post, $actor, 'deleted');
            $post->forceFill([
                'body_text' => '',
                'body_html' => null,
                'removed_at' => now(),
                'removed_by' => $this->isAuthor($actor, $post)
                    ? 'author' : ($actor->isOwner() ? 'owner' : 'moderator'),
                'edit_version' => $post->edit_version + 1,
            ])->save();

            // Keep the row, topic order, existing replies and public anchors.
        }, 3);

        $this->invalidateSearchAfterCommit();
        $this->audit->success('forum', 'forum.post_deleted',
            actor: $actor->admin ?? $actor->user, target: 'post #'.$postId);
    }

    private function invalidateSearchAfterCommit(): void
    {
        DB::afterCommit(static fn () => Cache::forever(self::SEARCH_VERSION, bin2hex(random_bytes(16))));
    }

    /** @return array{0:ForumPost, 1:ForumTopic, 2:ForumSection} */
    private function lockPost(int $postId): array
    {
        $initialPost = ForumPost::query()->findOrFail($postId);
        $initialTopic = ForumTopic::query()->findOrFail($initialPost->topic_id);

        // Match reply creation lock order: section, topic, then post.
        $section = ForumSection::query()->lockForUpdate()->findOrFail($initialTopic->section_id);
        ForumCategory::query()->findOrFail($section->category_id);
        $topic = ForumTopic::query()->lockForUpdate()->findOrFail($initialTopic->id);
        $post = ForumPost::query()->lockForUpdate()->findOrFail($postId);

        abort_if((int) $topic->section_id !== (int) $section->id
            || (int) $post->topic_id !== (int) $topic->id, 409);

        return [$post, $topic, $section];
    }

    private function assertVersion(ForumPost $post, int $version): void
    {
        if ($post->edit_version !== $version) {
            throw ValidationException::withMessages([
                'version' => __('module-forum::messages.edit_conflict'),
            ]);
        }
    }

    private function isAuthor(ForumActor $actor, ForumPost $post): bool
    {
        return match ($actor->mode) {
            'admin' => $actor->admin !== null && $post->author_type === 'admin'
                && $post->author_admin_id !== null
                && (int) $post->author_admin_id === (int) $actor->admin->id,
            'user' => $actor->user !== null && $post->author_type === 'user'
                && $post->author_user_id !== null
                && (int) $post->author_user_id === (int) $actor->user->id,
            default => false,
        };
    }

    private function isCurrentModerator(int $userId): bool
    {
        return $this->currentModerators[$userId] ??= DB::table('module_forum_moderators')
            ->where('user_id', $userId)->exists();
    }

    private function snapshot(ForumPost $post, ForumActor $actor, string $action): void
    {
        DB::table('module_forum_post_revisions')->insert([
            'post_id' => $post->id,
            'action' => $action,
            'body_text' => $post->body_text,
            'body_html' => $post->body_html,
            'changed_by_type' => $actor->mode,
            'changed_by_id' => ($actor->admin ?? $actor->user)?->getKey(),
            'changed_by_name' => $actor->name() ?? '',
            'created_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }
}
