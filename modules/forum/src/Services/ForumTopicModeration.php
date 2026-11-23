<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;

final class ForumTopicModeration
{
    public const ACTIONS = ['close', 'reopen', 'pin', 'unpin', 'hide', 'unhide', 'delete', 'restore'];

    public function __construct(
        private readonly ForumPermissions $permissions,
        private readonly ForumStructure $structure,
        private readonly AuditLogger $audit,
    ) {}

    public function isStaff(ForumActor $actor): bool
    {
        return $this->permissions->isModerator($actor);
    }

    public function canModerate(ForumActor $actor, ForumTopic $topic, ForumSection $section): bool
    {
        if (! $this->isStaff($actor)
            || ! $this->permissions->canView($actor, $section, $this->structure->globalVisibility())) {
            return false;
        }

        if ($actor->isOwner()) {
            return true;
        }

        // Topic ownership is not inferred from a display name or badge.
        if ($topic->author_type !== 'user' || $topic->author_user_id === null) {
            return false;
        }

        if ((int) $topic->author_user_id === (int) $actor->user?->id) {
            return true;
        }

        // Protect current and former moderator authors; the first post carries
        // the original trusted role snapshot, even after later revocation.
        if (DB::table('module_forum_moderators')->where('user_id', $topic->author_user_id)->exists()) {
            return false;
        }

        return ForumPost::query()->where('topic_id', $topic->id)
            ->orderBy('id')->value('author_role') !== 'moderator';
    }

    /**
     * Return only topics that this actor may manage. Filter before pagination:
     * a moderator must not learn the titles or counts of restricted staff topics.
     *
     * @return Builder<ForumTopic>
     */
    public function recoverableTopics(ForumActor $actor, ForumSection $section): Builder
    {
        $query = ForumTopic::withTrashed()
            ->where('section_id', $section->id)
            ->where(static fn (Builder $q) => $q
                ->whereNotNull('hidden_at')->orWhereNotNull('deleted_at'));

        if ($actor->isOwner()) {
            return $query;
        }

        $ownId = (int) $actor->user?->id;

        return $query->where('author_type', 'user')->where(
            static function (Builder $q) use ($ownId): void {
                $q->where('author_user_id', $ownId)
                    ->orWhere(static function (Builder $others): void {
                        $others->whereNotNull('author_user_id')
                            ->whereNotIn('author_user_id',
                                DB::table('module_forum_moderators')->select('user_id'))
                            ->whereDoesntHave('posts', static function (Builder $posts): void {
                                $posts->where('author_role', 'moderator')
                                    ->whereRaw('module_forum_posts.id = (SELECT MIN(initial.id) FROM module_forum_posts AS initial WHERE initial.topic_id = module_forum_topics.id)');
                            });
                    });
            }
        );
    }

    /**
     * @return array{changed:bool, section_id:int, public:bool}
     */
    public function change(ForumActor $actor, int $id, string $action): array
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        $result = DB::transaction(function () use ($actor, $id, $action): array {
            $original = ForumTopic::withTrashed()->findOrFail($id);
            // A topic of a deleted section (or category) comes back only after its section.
            if ($action === 'restore' && (! ForumSection::query()->whereKey($original->section_id)->exists()
                || ! ForumSection::query()->whereKey($original->section_id)->whereHas('category')->exists())) {
                throw ValidationException::withMessages([
                    'restore' => __('module-forum::messages.restore_section_first'),
                ]);
            }
            $section = ForumSection::query()->lockForUpdate()->findOrFail($original->section_id);
            ForumCategory::query()->findOrFail($section->category_id);
            $this->permissions->assertCurrentModeratorForWrite($actor);
            $topic = ForumTopic::withTrashed()->lockForUpdate()->findOrFail($id);
            abort_unless((int) $topic->section_id === (int) $section->id, 409);
            abort_unless($this->canModerate($actor, $topic, $section), 403);

            if ($topic->trashed() && $action !== 'restore') {
                abort(404);
            }

            // Hiding or deleting the whole topic also hides every reply.
            // A forum moderator must not indirectly suppress CMS staff posts.
            if (in_array($action, ['hide', 'unhide', 'delete', 'restore'], true) && ! $this->canRemove($actor, $topic)) {
                // A plain explanation instead of an error page; the topic stays as it is.
                throw ValidationException::withMessages([
                    'moderation' => __('module-forum::messages.moderation_protected_replies'),
                ]);
            }

            $changed = match ($action) {
                'close' => $this->setStatus($topic, 'closed'),
                'reopen' => $this->setStatus($topic, 'open'),
                'pin' => $this->setPinned($topic, true),
                'unpin' => $this->setPinned($topic, false),
                'hide' => $this->setHidden($topic, true),
                'unhide' => $this->setHidden($topic, false),
                'delete' => $this->softDelete($topic),
                'restore' => $this->restore($topic),
            };

            return [
                'changed' => $changed,
                'section_id' => (int) $section->id,
                'public' => ! $topic->trashed() && $topic->hidden_at === null,
            ];
        }, 3);

        if ($result['changed']) {
            // Hidden, deleted, restored or pinned topics must not wait for the activity cache.
            ForumActivity::forget();
            $this->audit->success('forum', 'forum.topic_'.$action,
                actor: $actor->admin ?? $actor->user,
                target: 'topic #'.$id,
            );
        }

        return $result;
    }

    /**
     * Moving to a more public section requires explicit Owner approval.
     * Moderators cannot broaden a discussion's readership.
     */
    public function move(ForumActor $actor, int $id, int $destinationId, bool $confirmExposure): bool
    {
        $result = DB::transaction(function () use ($actor, $id, $destinationId, $confirmExposure): array {
            $original = ForumTopic::withTrashed()->findOrFail($id);
            abort_if($original->trashed(), 404);

            // Lock both sections in numeric order to reduce cross-move deadlocks.
            $ids = array_values(array_unique([(int) $original->section_id, $destinationId]));
            sort($ids, SORT_NUMERIC);
            $sections = ForumSection::query()->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $sections->get((int) $original->section_id);
            $target = $sections->get($destinationId);
            abort_unless($source instanceof ForumSection && $target instanceof ForumSection, 404);
            ForumCategory::query()->findOrFail($source->category_id);
            ForumCategory::query()->findOrFail($target->category_id);

            $this->permissions->assertCurrentModeratorForWrite($actor);
            $topic = ForumTopic::query()->lockForUpdate()->findOrFail($id);
            abort_unless((int) $topic->section_id === (int) $source->id, 409);
            abort_unless($this->canModerate($actor, $topic, $source), 403);
            abort_unless($this->permissions->canView($actor, $target, $this->structure->globalVisibility()), 404);

            if ($source->id === $target->id) {
                return [false, (int) $source->id];
            }

            $sourceLevel = $this->visibilityLevel($source);
            $targetLevel = $this->visibilityLevel($target);
            if ($targetLevel > $sourceLevel && ! $this->canRemove($actor, $topic)) {
                // Moving into a more private section would hide protected replies from readers.
                throw ValidationException::withMessages([
                    'section_id' => __('module-forum::messages.moderation_protected_replies'),
                ]);
            }
            if ($targetLevel < $sourceLevel && (! $actor->isOwner() || ! $confirmExposure)) {
                throw ValidationException::withMessages([
                    'section_id' => __('module-forum::messages.move_private_warning'),
                ]);
            }

            $topic->forceFill(['section_id' => $target->id])->save();

            return [true, (int) $source->id];
        }, 3);

        if ($result[0]) {
            ForumActivity::forget();
            $this->audit->success('forum', 'forum.topic_moved',
                actor: $actor->admin ?? $actor->user,
                target: 'topic #'.$id,
                details: ['from_section_id' => $result[1], 'to_section_id' => $destinationId],
            );
        }

        return $result[0];
    }

    /**
     * Hiding or deleting a whole topic also hides every reply in it, so a forum
     * moderator may not do it when the topic holds CMS staff or other moderators' posts.
     */
    public function canRemove(ForumActor $actor, ForumTopic $topic): bool
    {
        return $actor->isOwner() || ! $this->containsProtectedReplies($topic, $actor);
    }

    private function containsProtectedReplies(ForumTopic $topic, ForumActor $actor): bool
    {
        $ownId = (int) $actor->user?->id;

        return ForumPost::query()->where('topic_id', $topic->id)
            ->where(static fn ($q) => $q
                ->where('author_type', 'admin')
                ->orWhere(static fn ($other) => $other
                    ->where(static fn ($notOwn) => $notOwn
                        ->whereNull('author_user_id')->orWhere('author_user_id', '!=', $ownId))
                    ->where(static fn ($privileged) => $privileged
                        ->where('author_role', 'moderator')
                        ->orWhereIn('author_user_id',
                            DB::table('module_forum_moderators')->select('user_id')))))
            ->exists();
    }

    private function visibilityLevel(ForumSection $section): int
    {
        return match ($section->view_rule) {
            'all' => 0,
            'registered' => 1,
            'moderators' => 2,
            default => 3,
        };
    }

    private function setStatus(ForumTopic $topic, string $status): bool
    {
        if ($topic->status === $status) {
            return false;
        }

        $topic->forceFill(['status' => $status])->save();

        return true;
    }

    private function setPinned(ForumTopic $topic, bool $pinned): bool
    {
        if ($topic->is_pinned === $pinned) {
            return false;
        }

        $topic->forceFill(['is_pinned' => $pinned])->save();

        return true;
    }

    private function setHidden(ForumTopic $topic, bool $hidden): bool
    {
        if (($topic->hidden_at !== null) === $hidden) {
            return false;
        }

        $topic->forceFill(['hidden_at' => $hidden ? now() : null])->save();

        return true;
    }

    private function softDelete(ForumTopic $topic): bool
    {
        $topic->delete();

        return true;
    }

    private function restore(ForumTopic $topic): bool
    {
        if (! $topic->trashed()) {
            return false;
        }

        $topic->restore();

        return true;
    }
}
