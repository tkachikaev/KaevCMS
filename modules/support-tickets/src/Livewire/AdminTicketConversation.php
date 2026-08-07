<?php

namespace KaevCMS\Modules\SupportTickets\Livewire;

use App\Models\Admin;
use App\Services\Admin\AdminPathSettings;
use App\Support\Modules\ModuleAdminComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessage;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketService;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;
use Livewire\Attributes\Locked;

final class AdminTicketConversation extends ModuleAdminComponent
{
    private const ROUTE_SHOW = 'admin.module-pages.support-tickets.show';

    private const ROUTE_ASSIGN = 'admin.module-pages.support-tickets.assign';

    private const ROUTE_REPLY = 'admin.module-pages.support-tickets.reply';

    private const ROUTE_NOTE = 'admin.module-pages.support-tickets.note';

    private const ROUTE_CLOSE = 'admin.module-pages.support-tickets.close';

    private const ROUTE_REOPEN = 'admin.module-pages.support-tickets.reopen';

    private const ROUTE_RETENTION_PROTECTION = 'admin.module-pages.support-tickets.retention-protection';

    private const ROUTE_DESTROY = 'admin.module-pages.support-tickets.destroy';

    private const ROUTE_MESSAGE_UPDATE = 'admin.module-pages.support-tickets.messages.update';

    #[Locked]
    public int $ticketId;

    #[Locked]
    public string $adminPath;

    public string $body = '';

    public string $noteBody = '';

    public bool $noteOpen = false;

    #[Locked]
    public int $visibleMessages = SupportTicket::MESSAGES_PER_PAGE;

    public ?int $editingMessageId = null;

    public string $editingBody = '';

    public ?string $notice = null;

    public function mount(int $ticketId, ?string $adminPath = null): void
    {
        $this->ticketId = $ticketId;
        $this->adminPath = is_string($adminPath) && $adminPath !== ''
            ? $adminPath
            : app(AdminPathSettings::class)->path();
        $this->ensureCanView();
        $this->ticket();
    }

    public function hydrate(): void
    {
        $this->ensureCanView();
        $this->ticket();
    }

    public function loadPrevious(): void
    {
        $this->ensureCanView();
        $this->visibleMessages = min(
            SupportTicketSettings::MAX_MAX_MESSAGES_PER_TICKET,
            $this->visibleMessages + SupportTicket::MESSAGES_PER_PAGE,
        );
    }

    public function assignToMe(SupportTicketService $tickets): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_ASSIGN, 'PATCH');
        $tickets->assignTo($this->ticket(), $this->admin());
        $this->notice = __('module-support-tickets::messages.ticket_assigned');
    }

    public function reply(SupportTicketService $tickets): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_REPLY, 'POST');
        $this->body = trim($this->body);
        $messageMaxLength = app(SupportTicketSettings::class)->messageMaxLength();
        $validated = $this->validate([
            'body' => ['required', 'string', 'min:1', 'max:'.$messageMaxLength],
        ], attributes: [
            'body' => __('module-support-tickets::messages.message'),
        ]);
        if (! $this->allowStaffMessage('body')) {
            return;
        }

        $tickets->replyAsStaff($this->ticket(), $this->admin(), (string) $validated['body']);
        $this->reset('body');
        $this->notice = __('module-support-tickets::messages.reply_sent');
        $this->dispatch('support-conversation-updated');
        $this->refreshAttentionBadge();
    }

    public function toggleNote(): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_NOTE, 'POST');
        $this->noteOpen = ! $this->noteOpen;
        if (! $this->noteOpen) {
            $this->resetValidation('noteBody');
        }
    }

    public function addNote(SupportTicketService $tickets): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_NOTE, 'POST');
        $this->noteBody = trim($this->noteBody);
        $messageMaxLength = app(SupportTicketSettings::class)->messageMaxLength();
        $validated = $this->validate([
            'noteBody' => ['required', 'string', 'min:1', 'max:'.$messageMaxLength],
        ], attributes: [
            'noteBody' => __('module-support-tickets::messages.message'),
        ]);
        if (! $this->allowStaffMessage('noteBody')) {
            return;
        }

        $tickets->addInternalNote($this->ticket(), $this->admin(), (string) $validated['noteBody']);
        $this->reset('noteBody');
        $this->noteOpen = false;
        $this->notice = __('module-support-tickets::messages.internal_note_added');
        $this->dispatch('support-conversation-updated');
    }

    public function closeTicket(SupportTicketService $tickets): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_CLOSE, 'PATCH');
        $tickets->closeByStaff($this->ticket(), $this->admin());
        $this->notice = __('module-support-tickets::messages.ticket_closed');
        $this->refreshAttentionBadge();
    }

    public function reopenTicket(SupportTicketService $tickets): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_REOPEN, 'PATCH');
        $tickets->reopenByStaff($this->ticket(), $this->admin());
        $this->notice = __('module-support-tickets::messages.ticket_reopened');
        $this->refreshAttentionBadge();
    }

    public function toggleRetentionProtection(SupportTicketService $tickets): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_RETENTION_PROTECTION, 'PATCH');
        $ticket = $this->ticket();
        $tickets->setRetentionProtected($ticket, $this->admin(), ! $ticket->retention_protected);
        $this->notice = __('module-support-tickets::messages.retention_protection_updated');
    }

    public function startEditing(int $messageId): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_MESSAGE_UPDATE, 'PUT');
        $message = $this->editableMessage($messageId);
        $this->editingMessageId = $message->id;
        $this->editingBody = $message->body;
        $this->resetValidation('editingBody');
    }

    public function cancelEditing(): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_MESSAGE_UPDATE, 'PUT');
        $this->reset(['editingMessageId', 'editingBody']);
        $this->resetValidation('editingBody');
    }

    public function saveEditing(SupportTicketService $tickets): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_MESSAGE_UPDATE, 'PUT');
        abort_unless($this->editingMessageId !== null, 404);
        $message = $this->editableMessage($this->editingMessageId);
        $this->editingBody = trim($this->editingBody);
        $messageMaxLength = app(SupportTicketSettings::class)->messageMaxLength();
        $validated = $this->validate([
            'editingBody' => ['required', 'string', 'min:1', 'max:'.$messageMaxLength],
        ], attributes: [
            'editingBody' => __('module-support-tickets::messages.message'),
        ]);

        $tickets->editStaffMessage($message, $this->admin(), (string) $validated['editingBody']);
        $this->reset(['editingMessageId', 'editingBody']);
        $this->notice = __('module-support-tickets::messages.message_updated');
    }

    public function render(): View
    {
        $messageMaxLength = app(SupportTicketSettings::class)->messageMaxLength();
        $admin = $this->admin();
        $ticket = $this->ticket()->load('assignedAdmin');
        $this->visibleMessages = min(
            SupportTicketSettings::MAX_MAX_MESSAGES_PER_TICKET,
            max(SupportTicket::MESSAGES_PER_PAGE, $this->visibleMessages),
        );
        $query = SupportTicketMessage::query()->where('ticket_id', $ticket->id);
        $totalMessages = (clone $query)->count();
        /** @var Collection<int, SupportTicketMessage> $messages */
        $messages = $query
            ->with('revisions')
            ->orderByDesc('id')
            ->limit($this->visibleMessages)
            ->get()
            ->reverse()
            ->values();

        /** @var view-string $view */
        $view = 'module-support-tickets::livewire.admin-ticket-conversation';

        return view($view, [
            'ticket' => $ticket,
            'messages' => $messages,
            'admin' => $admin,
            'hasPreviousMessages' => $totalMessages > $messages->count(),
            'canReply' => $this->canReply($admin),
            'canAddInternalNote' => $this->canAddInternalNote($admin),
            'canManageRetention' => $this->canUseModuleAdminRoute(self::ROUTE_RETENTION_PROTECTION, 'PATCH', $admin),
            'canDelete' => $this->canUseModuleAdminRoute(self::ROUTE_DESTROY, 'DELETE', $admin),
            'messageMaxLength' => $messageMaxLength,
        ]);
    }

    private function refreshAttentionBadge(): void
    {
        $this->dispatch('module-admin-badge-refresh', moduleId: 'support-tickets');
    }

    private function allowStaffMessage(string $field): bool
    {
        $rateLimitKey = 'support-ticket-staff-message:'.$this->admin()->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            $this->addError($field, __('module-support-tickets::messages.rate_limit_retry', [
                'seconds' => RateLimiter::availableIn($rateLimitKey),
            ]));

            return false;
        }

        RateLimiter::hit($rateLimitKey, 60);

        return true;
    }

    private function editableMessage(int $messageId): SupportTicketMessage
    {
        $message = SupportTicketMessage::query()->findOrFail($messageId);
        abort_unless($message->ticket_id === $this->ticketId, 404);
        abort_unless($message->isStaffMessage() && $message->admin_id === $this->admin()->id, 403);
        if ($message->is_internal) {
            $this->authorizeModuleAdminRoute(self::ROUTE_NOTE, 'POST');
        } else {
            $this->authorizeModuleAdminRoute(self::ROUTE_REPLY, 'POST');
        }

        return $message;
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::query()->findOrFail($this->ticketId);
    }

    private function ensureCanView(): void
    {
        $this->authorizeModuleAdminRoute(self::ROUTE_SHOW, 'GET');
    }

    private function canReply(Admin $admin): bool
    {
        return $this->canUseModuleAdminRoute(self::ROUTE_REPLY, 'POST', $admin);
    }

    private function canAddInternalNote(Admin $admin): bool
    {
        return $this->canUseModuleAdminRoute(self::ROUTE_NOTE, 'POST', $admin);
    }

    private function admin(): Admin
    {
        return $this->moduleAdminActor();
    }
}
