<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Services\Html\SafeHtmlSanitizer;
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
        private readonly SafeHtmlSanitizer $htmlSanitizer,
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

    public function createTopic(ForumActor $actor, int $sectionId, string $title, string $body, string $key, ?string $bodyHtml = null): ForumTopic
    {
        $this->assertAuthor($actor);
        $this->assertContent($actor, $title, 'title');
        [$body, $bodyHtml] = $this->prepareBody($actor, $body, $bodyHtml);
        $title = trim($title);

        return DB::transaction(function () use ($actor, $sectionId, $title, $body, $bodyHtml, $key): ForumTopic {
            $section = ForumSection::query()->lockForUpdate()->findOrFail($sectionId);
            ForumCategory::query()->findOrFail($section->category_id);
            abort_unless($this->canStart($actor, $section), 403);

            $existing = ForumTopic::withTrashed()->where('submission_key', $key)->first();
            if ($existing !== null) {
                abort_unless($existing->deleted_at === null && $existing->hidden_at === null
                    && (int) $existing->section_id === $sectionId && $this->sameAuthor($existing, $actor), 409);

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
                'body_html' => $bodyHtml,
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            return $topic;
        });
    }

    public function addReply(ForumActor $actor, int $topicId, string $body, string $key, ?string $bodyHtml = null): ForumPost
    {
        $this->assertAuthor($actor);
        [$body, $bodyHtml] = $this->prepareBody($actor, $body, $bodyHtml);

        return DB::transaction(function () use ($actor, $topicId, $body, $bodyHtml, $key): ForumPost {
            // Lock the section before the topic: changing section permissions and replying
            // cannot silently race through two different authorization decisions.
            $initialTopic = ForumTopic::query()->findOrFail($topicId);
            $section = ForumSection::query()->lockForUpdate()->findOrFail($initialTopic->section_id);
            ForumCategory::query()->findOrFail($section->category_id);
            $topic = ForumTopic::query()->lockForUpdate()->findOrFail($topicId);

            abort_unless((int) $topic->section_id === (int) $section->id && $this->canReply($actor, $topic, $section), 403);

            $existing = ForumPost::withTrashed()->where('submission_key', $key)->first();
            if ($existing !== null) {
                abort_unless($existing->deleted_at === null && (int) $existing->topic_id === $topicId
                    && $this->sameAuthor($existing, $actor), 409);

                return $existing;
            }

            $author = $this->author($actor);
            $date = now();
            $post = ForumPost::query()->create($author + [
                'topic_id' => $topic->id,
                'body_text' => $body,
                'body_html' => $bodyHtml,
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

    /**
     * @return array{0:string, 1:string|null}
     */
    public function prepareBody(ForumActor $actor, string $plain, ?string $html): array
    {
        if ($html === null || trim($html) === '') {
            $plain = trim($plain);
            $this->assertContent($actor, $plain);

            return [$plain, null];
        }

        // The news/pages sanitizer provides the DOM parser and URL checks; the forum
        // profile only admits simple text, lists, quotes, code, and safe links.
        $safeHtml = $this->htmlSanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_FORUM);
        $text = $this->htmlSanitizer->plainText($safeHtml, SafeHtmlSanitizer::PROFILE_FORUM);

        if (mb_strlen($text) < 2 || mb_strlen($text) > 10000) {
            throw ValidationException::withMessages([
                'body' => __('module-forum::messages.body_required'),
            ]);
        }

        $this->assertContent($actor, $text);
        // A link can use innocuous label text with a prohibited destination.
        $this->assertContent($actor, $safeHtml);

        return [$text, $safeHtml];
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

    private function assertContent(ForumActor $actor, string $body, string $field = 'body'): void
    {
        if (trim($body) === '') {
            throw ValidationException::withMessages([$field => __('module-forum::messages.body_required')]);
        }

        if ($actor->mode !== 'user' || $actor->user === null || $this->permissions->isModerator($actor)) {
            return;
        }

        $days = (int) DB::table('module_forum_settings')->where('id', 1)->value('links_after_days');
        if ($days > 0 && ($actor->user->created_at === null || $actor->user->created_at->copy()->addDays($days)->isFuture())
            && preg_match('~(?:https?://|www\.|[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+)~iu', $body) === 1) {
            throw ValidationException::withMessages([
                $field => __('module-forum::messages.links_not_allowed', ['days' => $days]),
            ]);
        }
    }

    /** @return array{author_type: string, author_user_id: int|null, author_admin_id: int|null, author_name: string, author_role: string|null} */
    private function author(ForumActor $actor): array
    {
        return [
            'author_type' => $actor->mode,
            'author_user_id' => $actor->user === null ? null : (int) $actor->user->getKey(),
            'author_admin_id' => $actor->admin === null ? null : (int) $actor->admin->getKey(),
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
