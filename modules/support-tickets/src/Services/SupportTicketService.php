<?php

namespace KaevCMS\Modules\SupportTickets\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\AdminNotificationCenter;
use App\Support\Notifications\AdminNotificationData;
use App\Support\Notifications\AdminNotificationSeverity;
use App\Support\Notifications\AdminNotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessage;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessageRevision;

final class SupportTicketService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SupportTicketSettings $settings,
        private readonly SupportTicketAttentionCounter $attentionCounter,
        private readonly AdminNotificationCenter $notifications,
    ) {}

    public function create(
        User $user,
        SupportTicketCategory $category,
        string $subject,
        string $body,
    ): SupportTicket {
        $ticket = DB::transaction(function () use ($user, $category, $subject, $body): SupportTicket {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertDailyTicketLimit($user);
            $this->assertDailyPlayerMessageLimit($user);
            $openCount = SupportTicket::query()
                ->where('user_id', $user->id)
                ->open()
                ->count();

            if ($openCount >= $this->settings->maxOpenTicketsPerUser()) {
                throw ValidationException::withMessages([
                    'subject' => __('module-support-tickets::messages.open_ticket_limit', [
                        'count' => $this->settings->maxOpenTicketsPerUser(),
                    ]),
                ]);
            }

            $now = now();
            $ticket = SupportTicket::query()->create([
                'user_id' => $user->id,
                'user_name_snapshot' => $user->name,
                'user_email_snapshot' => $user->email,
                'category' => $category,
                'status' => SupportTicketStatus::New,
                'subject' => $this->normalize($subject),
                'last_message_at' => $now,
                'last_player_message_at' => $now,
            ]);

            $ticket->messages()->create([
                'author_type' => SupportTicketMessage::AUTHOR_PLAYER,
                'user_id' => $user->id,
                'author_name_snapshot' => $user->name,
                'is_internal' => false,
                'body' => $this->normalize($body),
            ]);

            return $ticket;
        }, 3);

        $this->attentionCounter->forget();
        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.created',
            actor: $user,
            target: $ticket,
            details: [
                'ticket_id' => $ticket->id,
                'category' => $category->value,
                'status' => SupportTicketStatus::New->value,
            ],
        );
        $this->notifyStaff(
            type: AdminNotificationType::SupportTicketCreated,
            externalKey: "support-ticket-created:{$ticket->id}",
            titleKey: 'module-support-tickets::messages.notification_new_ticket_title',
            messageKey: 'module-support-tickets::messages.notification_new_ticket_message',
            ticket: $ticket,
        );

        return $ticket;
    }

    public function replyAsPlayer(SupportTicket $ticket, User $user, string $body): SupportTicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $user, $body): SupportTicketMessage {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless($locked->user_id === $user->id, 404);
            $this->assertOpen($locked);
            $this->assertDailyPlayerMessageLimit($user);
            $this->assertTicketMessageCapacity($locked);
            $normalized = $this->normalize($body);
            $this->assertNotDuplicate($locked, SupportTicketMessage::AUTHOR_PLAYER, $user->id, $normalized);
            $now = now();

            $message = $locked->messages()->create([
                'author_type' => SupportTicketMessage::AUTHOR_PLAYER,
                'user_id' => $user->id,
                'author_name_snapshot' => $user->name,
                'is_internal' => false,
                'body' => $normalized,
            ]);

            $locked->update([
                'status' => SupportTicketStatus::InProgress,
                'last_message_at' => $now,
                'last_player_message_at' => $now,
                'closed_at' => null,
                'closed_by_admin_id' => null,
                'closed_by_user_id' => null,
            ]);

            return $message;
        }, 3);

        $this->attentionCounter->forget();
        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.player_replied',
            actor: $user,
            target: $ticket,
            details: ['ticket_id' => $ticket->id, 'message_id' => $message->id],
        );
        $this->notifyStaff(
            type: AdminNotificationType::SupportTicketPlayerReply,
            externalKey: "support-ticket-player-reply:{$message->id}",
            titleKey: 'module-support-tickets::messages.notification_player_reply_title',
            messageKey: 'module-support-tickets::messages.notification_player_reply_message',
            ticket: $ticket,
        );

        return $message;
    }

    public function replyAsStaff(SupportTicket $ticket, Admin $admin, string $body): SupportTicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $admin, $body): SupportTicketMessage {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->assertOpen($locked);
            $this->assertTicketMessageCapacity($locked);
            $normalized = $this->normalize($body);
            $this->assertNotDuplicate($locked, SupportTicketMessage::AUTHOR_ADMIN, $admin->id, $normalized);
            $now = now();

            $message = $locked->messages()->create([
                'author_type' => SupportTicketMessage::AUTHOR_ADMIN,
                'admin_id' => $admin->id,
                'author_name_snapshot' => $admin->name,
                'admin_role_snapshot' => $admin->role->value,
                'is_internal' => false,
                'body' => $normalized,
            ]);

            $locked->update([
                'status' => SupportTicketStatus::AwaitingPlayer,
                'assigned_admin_id' => $locked->assigned_admin_id ?? $admin->id,
                'last_message_at' => $now,
                'last_staff_message_at' => $now,
            ]);

            return $message;
        }, 3);

        $this->attentionCounter->forget();
        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.staff_replied',
            actor: $admin,
            target: $ticket,
            details: ['ticket_id' => $ticket->id, 'message_id' => $message->id],
        );

        return $message;
    }

    public function addInternalNote(SupportTicket $ticket, Admin $admin, string $body): SupportTicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $admin, $body): SupportTicketMessage {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->assertTicketMessageCapacity($locked);
            $normalized = $this->normalize($body);
            $this->assertNotDuplicate($locked, SupportTicketMessage::AUTHOR_ADMIN, $admin->id, $normalized, true);

            return $locked->messages()->create([
                'author_type' => SupportTicketMessage::AUTHOR_ADMIN,
                'admin_id' => $admin->id,
                'author_name_snapshot' => $admin->name,
                'admin_role_snapshot' => $admin->role->value,
                'is_internal' => true,
                'body' => $normalized,
            ]);
        }, 3);

        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.internal_note_added',
            actor: $admin,
            target: $ticket,
            details: ['ticket_id' => $ticket->id, 'message_id' => $message->id],
        );

        return $message;
    }

    public function assignTo(SupportTicket $ticket, Admin $admin): void
    {
        $changed = DB::transaction(function () use ($ticket, $admin): bool {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $updates = [];

            if ($locked->assigned_admin_id !== $admin->id) {
                $updates['assigned_admin_id'] = $admin->id;
            }
            if ($locked->status === SupportTicketStatus::New) {
                $updates['status'] = SupportTicketStatus::InProgress;
            }
            if ($updates === []) {
                return false;
            }

            $locked->update($updates);

            return true;
        }, 3);

        if (! $changed) {
            return;
        }

        $this->attentionCounter->forget();
        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.assigned',
            actor: $admin,
            target: $ticket,
            details: ['ticket_id' => $ticket->id, 'assigned_admin_id' => $admin->id],
        );
    }

    public function closeByPlayer(SupportTicket $ticket, User $user): void
    {
        $changed = DB::transaction(function () use ($ticket, $user): bool {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless($locked->user_id === $user->id, 404);
            if ($locked->isClosed()) {
                return false;
            }

            $locked->update([
                'status' => SupportTicketStatus::Closed,
                'closed_at' => now(),
                'closed_by_user_id' => $user->id,
                'closed_by_admin_id' => null,
            ]);

            return true;
        }, 3);

        if (! $changed) {
            return;
        }

        $this->attentionCounter->forget();
        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.closed_by_player',
            actor: $user,
            target: $ticket,
            details: ['ticket_id' => $ticket->id],
        );
    }

    public function closeByStaff(SupportTicket $ticket, Admin $admin): void
    {
        $changed = DB::transaction(function () use ($ticket, $admin): bool {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->isClosed()) {
                return false;
            }

            $locked->update([
                'status' => SupportTicketStatus::Closed,
                'closed_at' => now(),
                'closed_by_admin_id' => $admin->id,
                'closed_by_user_id' => null,
            ]);

            return true;
        }, 3);

        if (! $changed) {
            return;
        }

        $this->attentionCounter->forget();
        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.closed_by_staff',
            actor: $admin,
            target: $ticket,
            details: ['ticket_id' => $ticket->id],
        );
    }

    public function reopenByStaff(SupportTicket $ticket, Admin $admin): void
    {
        DB::transaction(function () use ($ticket, $admin): void {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if (! $locked->isClosed()) {
                throw ValidationException::withMessages([
                    'status' => __('module-support-tickets::messages.ticket_is_not_closed'),
                ]);
            }

            $locked->update([
                'status' => SupportTicketStatus::InProgress,
                'assigned_admin_id' => $locked->assigned_admin_id ?? $admin->id,
                'closed_at' => null,
                'closed_by_admin_id' => null,
                'closed_by_user_id' => null,
            ]);
        }, 3);

        $this->attentionCounter->forget();
        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.reopened',
            actor: $admin,
            target: $ticket,
            details: ['ticket_id' => $ticket->id],
        );
    }

    public function setRetentionProtected(SupportTicket $ticket, Admin $admin, bool $protected): void
    {
        $changed = DB::transaction(function () use ($ticket, $protected): bool {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->retention_protected === $protected) {
                return false;
            }

            $locked->update(['retention_protected' => $protected]);

            return true;
        }, 3);

        if (! $changed) {
            return;
        }

        $this->auditLogger->success(
            category: 'module',
            action: $protected ? 'support_ticket.retention_protected' : 'support_ticket.retention_unprotected',
            actor: $admin,
            target: $ticket,
            details: ['ticket_id' => $ticket->id, 'retention_protected' => $protected],
        );
    }

    public function editStaffMessage(SupportTicketMessage $message, Admin $admin, string $body): void
    {
        DB::transaction(function () use ($message, $admin, $body): void {
            $locked = SupportTicketMessage::query()->lockForUpdate()->findOrFail($message->id);
            if (! $locked->isStaffMessage() || $locked->admin_id !== $admin->id) {
                abort(403);
            }

            $normalized = $this->normalize($body);
            if ($locked->revisions()->count() >= $this->settings->maxRevisionsPerMessage()) {
                throw ValidationException::withMessages([
                    'body' => __('module-support-tickets::messages.message_revision_limit', [
                        'count' => $this->settings->maxRevisionsPerMessage(),
                    ]),
                ]);
            }
            if (hash_equals($locked->body, $normalized)) {
                throw ValidationException::withMessages([
                    'body' => __('module-support-tickets::messages.message_not_changed'),
                ]);
            }

            SupportTicketMessageRevision::query()->create([
                'message_id' => $locked->id,
                'editor_admin_id' => $admin->id,
                'editor_name_snapshot' => $admin->name,
                'previous_body' => $locked->body,
                'edited_at' => now(),
            ]);

            $locked->update([
                'body' => $normalized,
                'edited_at' => now(),
            ]);
        }, 3);

        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.message_edited',
            actor: $admin,
            target: $message->ticket,
            details: ['ticket_id' => $message->ticket_id, 'message_id' => $message->id],
        );
    }

    private function notifyStaff(
        AdminNotificationType $type,
        string $externalKey,
        string $titleKey,
        string $messageKey,
        SupportTicket $ticket,
    ): void {
        $this->notifications->notifyOnce(
            new AdminNotificationData(
                type: $type,
                severity: AdminNotificationSeverity::Info,
                titleKey: $titleKey,
                messageKey: $messageKey,
                parameters: ['number' => $ticket->number()],
                routeName: 'admin.module-pages.support-tickets.show',
                routeParameters: ['ticket' => $ticket->id],
            ),
            $externalKey,
            recipientFilter: fn (Admin $admin): bool => match ($admin->role) {
                AdminRole::Owner, AdminRole::Administrator, AdminRole::Auditor => true,
                AdminRole::Editor => $this->settings->editorCanView(),
            },
        );
    }

    private function assertDailyTicketLimit(User $user): void
    {
        $count = SupportTicket::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($count >= $this->settings->maxTicketsPerDay()) {
            throw ValidationException::withMessages([
                'subject' => __('module-support-tickets::messages.daily_ticket_limit', [
                    'count' => $this->settings->maxTicketsPerDay(),
                ]),
            ]);
        }
    }

    private function assertDailyPlayerMessageLimit(User $user): void
    {
        $count = SupportTicketMessage::query()
            ->where('author_type', SupportTicketMessage::AUTHOR_PLAYER)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($count >= $this->settings->maxPlayerMessagesPerDay()) {
            throw ValidationException::withMessages([
                'body' => __('module-support-tickets::messages.daily_message_limit', [
                    'count' => $this->settings->maxPlayerMessagesPerDay(),
                ]),
            ]);
        }
    }

    private function assertTicketMessageCapacity(SupportTicket $ticket): void
    {
        if ($ticket->messages()->count() >= $this->settings->maxMessagesPerTicket()) {
            throw ValidationException::withMessages([
                'body' => __('module-support-tickets::messages.ticket_message_limit', [
                    'count' => $this->settings->maxMessagesPerTicket(),
                ]),
            ]);
        }
    }

    private function assertOpen(SupportTicket $ticket): void
    {
        if ($ticket->isClosed()) {
            throw ValidationException::withMessages([
                'body' => __('module-support-tickets::messages.closed_ticket_cannot_receive_messages'),
            ]);
        }
    }

    private function assertNotDuplicate(
        SupportTicket $ticket,
        string $authorType,
        int $authorId,
        string $body,
        bool $internal = false,
    ): void {
        $query = $ticket->messages()
            ->where('author_type', $authorType)
            ->where('is_internal', $internal)
            ->where('body', $body)
            ->where('created_at', '>=', now()->subMinute());

        $authorType === SupportTicketMessage::AUTHOR_PLAYER
            ? $query->where('user_id', $authorId)
            : $query->where('admin_id', $authorId);

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'body' => __('module-support-tickets::messages.duplicate_message'),
            ]);
        }
    }

    private function normalize(string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }
}
