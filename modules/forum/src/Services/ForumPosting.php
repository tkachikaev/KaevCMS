<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Services\RegistrationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;

final class ForumPosting
{
    public function __construct(
        private readonly ForumPermissions $permissions,
        private readonly ForumStructure $structure,
        private readonly RegistrationSettings $registration,
    ) {}

    public function canStart(ForumActor $actor, ForumSection $section): bool
    {
        return $this->permissions->canCreateTopic($actor, $section, $this->structure->globalVisibility());
    }

    public function canReply(ForumActor $actor, ForumTopic $topic, ForumSection $section): bool
    {
        return $topic->status === 'open'
            && $topic->hidden_at === null
            && $topic->deleted_at === null
            && $this->permissions->canReply($actor, $section, $this->structure->globalVisibility());
    }

    public function createTopic(ForumActor $actor, int $sectionId, string $title, string $body, string $key): ForumTopic
    {
        $this->assertAuthor($actor);
        $this->assertContent($actor, $body);
        $title = trim($title);
        $body = trim($body);

        return DB::transaction(function () use ($actor, $sectionId, $title, $body, $key): ForumTopic {
            $section = ForumSection::query()->lockForUpdate()->findOrFail($sectionId);
            ForumCategory::query()->findOrFail($section->category_id);
            abort_unless($this->canStart($actor, $section), 403);

            $existing = ForumTopic::query()->where('submission_key', $key)->first();
            if ($existing !== null) {
                abort_unless($existing->section_id === $sectionId && $this->sameAuthor($existing, $actor), 409);

                return $existing;
            }

            $author = $this->author($actor);
            $date = now();
            $topic = ForumTopic::query()->create([
                'section_id' => $section->id,
                'title' => $title,
                'status' => 'open',
                'author_type' => $author['author_type'],
                'author_user_id' => $author['author_user_id'],
                'author_admin_id' => $author['author_admin_id'],
                'author_name' => $author['author_name'],
                'last_author_name' => $author['author_name'],
                'last_post_at' => $date,
                'submission_key' => $key,
            ]);

            ForumPost::query()->create($author + [
                'topic_id' => $topic->id,
                'body_text' => $body,
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            return $topic;
        });
    }

    public function addReply(ForumActor $actor, int $topicId, string $body, string $key): ForumPost
    {
        $this->assertAuthor($actor);
        $this->assertContent($actor, $body);
        $body = trim($body);

        return DB::transaction(function () use ($actor, $topicId, $body, $key): ForumPost {
            // Lock the section before the topic: changing section permissions and replying
            // cannot silently race through two different authorization decisions.
            $initialTopic = ForumTopic::query()->findOrFail($topicId);
            $section = ForumSection::query()->lockForUpdate()->findOrFail($initialTopic->section_id);
            ForumCategory::query()->findOrFail($section->category_id);
            $topic = ForumTopic::query()->lockForUpdate()->findOrFail($topicId);

            abort_unless((int) $topic->section_id === (int) $section->id && $this->canReply($actor, $topic, $section), 403);

            $existing = ForumPost::query()->where('submission_key', $key)->first();
            if ($existing !== null) {
                abort_unless((int) $existing->topic_id === $topicId && $this->sameAuthor($existing, $actor), 409);

                return $existing;
            }

            $author = $this->author($actor);
            $date = now();
            $post = ForumPost::query()->create($author + [
                'topic_id' => $topic->id,
                'body_text' => $body,
                'submission_key' => $key,
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            $topic->forceFill([
                'reply_count' => $topic->reply_count + 1,
                'last_post_at' => $date,
                'last_author_name' => $author['author_name'],
            ])->save();

            return $post;
        });
    }

    private function assertAuthor(ForumActor $actor): void
    {
        abort_if($actor->isGuest(), 403);

        if ($actor->mode === 'user'
            && $actor->user !== null
            && $this->registration->emailVerificationRequired()
            && ! $actor->user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'body' => __('module-forum::messages.email_unverified'),
            ]);
        }
    }

    private function assertContent(ForumActor $actor, string $body): void
    {
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => __('module-forum::messages.body_required')]);
        }

        if ($actor->mode !== 'user' || $actor->user === null || $this->permissions->isModerator($actor)) {
            return;
        }

        $days = (int) DB::table('module_forum_settings')->where('id', 1)->value('links_after_days');
        if ($days > 0 && $actor->user->created_at->copy()->addDays($days)->isFuture()
            && preg_match('~(?:https?://|www\.|[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+)~iu', $body) === 1) {
            throw ValidationException::withMessages([
                'body' => __('module-forum::messages.links_not_allowed', ['days' => $days]),
            ]);
        }
    }

    /** @return array{author_type: string, author_user_id: int|null, author_admin_id: int|null, author_name: string, author_role: string|null} */
    private function author(ForumActor $actor): array
    {
        return [
            'author_type' => $actor->mode,
            'author_user_id' => $actor->user?->id,
            'author_admin_id' => $actor->admin?->id,
            'author_name' => (string) $actor->name(),
            'author_role' => $actor->mode === 'admin' ? 'administrator'
                : ($this->permissions->isModerator($actor) ? 'moderator' : null),
        ];
    }

    private function sameAuthor(ForumTopic|ForumPost $record, ForumActor $actor): bool
    {
        return $record->author_type === $actor->mode
            && ($actor->mode === 'admin'
                ? (int) $record->author_admin_id === $actor->admin?->id
                : (int) $record->author_user_id === $actor->user?->id);
    }
}
