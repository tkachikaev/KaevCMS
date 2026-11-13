<?php

namespace KaevCMS\Modules\NewsComments\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\ModuleState;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\NewsComments\Models\NewsComment;
use KaevCMS\Modules\NewsComments\Models\NewsCommentSetting;

final class NewsCommentModerationService
{
    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    public const ACTION_APPROVE_EDIT = 'approve_edit';

    public const ACTION_REJECT_EDIT = 'reject_edit';

    public const ACTION_HIDE = 'hide';

    public const ACTION_RESTORE = 'restore';

    public const ACTION_DESTROY = 'destroy';

    public function __construct(
        private readonly NewsCommentSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function canModerate(Admin $admin): bool
    {
        if (! $admin->is_active) {
            return false;
        }

        return match ($admin->role) {
            AdminRole::Owner, AdminRole::Administrator => true,
            AdminRole::Editor => $this->settings->editorCanModerate(),
            default => false,
        };
    }

    public function canDelete(Admin $admin): bool
    {
        return $admin->is_active && in_array($admin->role, [
            AdminRole::Owner, AdminRole::Administrator,
        ], true);
    }

    public function pendingCount(): int
    {
        if ($this->settings->values() === null) {
            return 0;
        }

        return NewsComment::query()
            ->whereNull('deleted_by_author_at')
            ->where(static function (Builder $query): void {
                $query->where('status', 'pending')
                    ->orWhere(static function (Builder $edits): void {
                        $edits->where('status', 'approved')
                            ->whereNotNull('pending_body');
                    });
            })
            ->count();
    }

    /**
     * Reject stale review actions rather than silently approving a different
     * revision or restoring a comment that its author already deleted.
     */
    public function act(Admin $actor, int $commentId, string $action, ?string $expectedRevisionHash = null): void
    {
        abort_unless(auth('admin')->id() === $actor->getAuthIdentifier(), 403);
        abort_unless(in_array($action, [
            self::ACTION_APPROVE,
            self::ACTION_REJECT,
            self::ACTION_APPROVE_EDIT,
            self::ACTION_REJECT_EDIT,
            self::ACTION_HIDE,
            self::ACTION_RESTORE,
            self::ACTION_DESTROY,
        ], true), 404);

        $result = DB::transaction(function () use ($actor, $commentId, $action, $expectedRevisionHash): array {
            $module = ModuleState::query()->whereKey('news-comments')->lockForUpdate()->first();
            abort_unless($module !== null && $module->enabled, 404);

            // Take the settings lock before a comment lock. Player edits use the
            // same order so a pending revision cannot be approved after deletion.
            $setting = NewsCommentSetting::query()->whereKey(1)->lockForUpdate()->first();
            abort_unless($setting !== null && $this->settings->values() !== null, 503);

            $admin = Admin::query()->whereKey($actor->getKey())->firstOrFail();
            abort_unless(
                $action === self::ACTION_DESTROY
                    ? $this->canDelete($admin)
                    : $this->canModerate($admin),
                403,
            );

            $comment = NewsComment::query()->whereKey($commentId)->lockForUpdate()->firstOrFail();
            if ($comment->deleted_by_author_at !== null && $action !== self::ACTION_DESTROY) {
                $this->conflict();
            }

            $before = $comment->status;
            $type = $comment->parent_id === null ? 'root' : 'reply';
            $details = [
                'comment_id' => $comment->id,
                'news_id' => $comment->news_id,
                'comment_type' => $type,
                'previous_status' => $before,
            ];

            switch ($action) {
                case self::ACTION_APPROVE:
                case self::ACTION_REJECT:
                    if ($before !== 'pending' || $comment->pending_body !== null || $comment->body === null) {
                        $this->conflict();
                    }

                    $comment->forceFill([
                        'status' => $action === self::ACTION_APPROVE ? 'approved' : 'rejected',
                        'moderated_by_admin_id' => $admin->id,
                        'moderated_at' => now(),
                    ])->save();
                    break;

                case self::ACTION_APPROVE_EDIT:
                case self::ACTION_REJECT_EDIT:
                    if ($before !== 'approved' || $comment->pending_body === null
                        || $comment->pending_edited_at === null || $comment->body === null
                        || $expectedRevisionHash === null
                        || ! hash_equals(hash('sha256', $comment->pending_body), $expectedRevisionHash)) {
                        $this->conflict();
                    }

                    $accepted = $action === self::ACTION_APPROVE_EDIT;
                    $comment->forceFill([
                        'body' => $accepted ? $comment->pending_body : $comment->body,
                        'pending_body' => null,
                        'pending_edited_at' => null,
                        'edited_at' => $accepted ? now() : $comment->edited_at,
                        'moderated_by_admin_id' => $admin->id,
                        'moderated_at' => now(),
                    ])->save();
                    break;

                case self::ACTION_HIDE:
                    if ($before !== 'approved') {
                        $this->conflict();
                    }

                    $comment->forceFill([
                        'status' => 'hidden',
                        'pending_body' => null,
                        'pending_edited_at' => null,
                        'moderated_by_admin_id' => $admin->id,
                        'moderated_at' => now(),
                    ])->save();
                    break;

                case self::ACTION_RESTORE:
                    if (! in_array($before, ['rejected', 'hidden'], true) || $comment->body === null) {
                        $this->conflict();
                    }

                    $comment->forceFill([
                        'status' => 'approved',
                        'moderated_by_admin_id' => $admin->id,
                        'moderated_at' => now(),
                    ])->save();
                    break;

                case self::ACTION_DESTROY:
                    $details['direct_replies_deleted'] = $comment->replies()->count();
                    $comment->delete();
                    break;
            }

            $details['new_status'] = $action === self::ACTION_DESTROY ? 'deleted' : $comment->status;

            return $details;
        }, 3);

        // Never store comment contents in the audit trail. Audit uses stable
        // metadata and a string identifier because the row may be deleted.
        $this->audit->success(
            category: 'module',
            action: 'news_comments.'.$action,
            actor: $actor,
            target: 'comment:'.$commentId,
            details: $result,
        );
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages([
            'moderation' => __('module-news-comments::messages.moderation_conflict'),
        ]);
    }
}
