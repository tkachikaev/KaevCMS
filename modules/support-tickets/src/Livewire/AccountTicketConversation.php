<?php

namespace KaevCMS\Modules\SupportTickets\Livewire;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessage;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketService;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class AccountTicketConversation extends Component
{
    #[Locked]
    public int $ticketId;

    public string $body = '';

    public int $visibleMessages = SupportTicket::MESSAGES_PER_PAGE;

    public ?string $notice = null;

    public function mount(int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $this->ticket();
    }

    public function hydrate(): void
    {
        $this->ticket();
    }

    public function loadPrevious(): void
    {
        $this->visibleMessages = min(
            app(SupportTicketSettings::class)->maxMessagesPerTicket(),
            $this->visibleMessages + SupportTicket::MESSAGES_PER_PAGE,
        );
    }

    public function reply(SupportTicketService $tickets): void
    {
        $ticket = $this->ticket();
        $user = $this->user();
        $rateLimitKey = 'support-ticket-player-reply:'.$user->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            $this->addError('body', __('module-support-tickets::messages.rate_limit_retry', [
                'seconds' => RateLimiter::availableIn($rateLimitKey),
            ]));

            return;
        }

        $this->body = trim($this->body);
        $messageMaxLength = app(SupportTicketSettings::class)->messageMaxLength();
        $validated = $this->validate([
            'body' => ['required', 'string', 'min:1', 'max:'.$messageMaxLength],
        ], attributes: [
            'body' => __('module-support-tickets::messages.message'),
        ]);

        RateLimiter::hit($rateLimitKey, 60);
        $tickets->replyAsPlayer($ticket, $user, (string) $validated['body']);
        $this->reset('body');
        $this->notice = __('module-support-tickets::messages.reply_sent');
        $this->dispatch('support-conversation-updated');
    }

    public function closeTicket(SupportTicketService $tickets): void
    {
        $tickets->closeByPlayer($this->ticket(), $this->user());
        $this->notice = __('module-support-tickets::messages.ticket_closed');
    }

    public function render(): View
    {
        $messageMaxLength = app(SupportTicketSettings::class)->messageMaxLength();
        $ticket = $this->ticket()->load('assignedAdmin');
        $this->visibleMessages = min(
            app(SupportTicketSettings::class)->maxMessagesPerTicket(),
            max(SupportTicket::MESSAGES_PER_PAGE, $this->visibleMessages),
        );
        $query = SupportTicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('is_internal', false);
        $totalMessages = (clone $query)->count();
        /** @var Collection<int, SupportTicketMessage> $messages */
        $messages = $query
            ->orderByDesc('id')
            ->limit($this->visibleMessages)
            ->get()
            ->reverse()
            ->values();

        return view('module-support-tickets::livewire.account-ticket-conversation', [
            'ticket' => $ticket,
            'messages' => $messages,
            'hasPreviousMessages' => $totalMessages > $messages->count(),
            'messageMaxLength' => $messageMaxLength,
        ]);
    }

    private function ticket(): SupportTicket
    {
        $ticket = SupportTicket::query()->findOrFail($this->ticketId);
        abort_unless($ticket->user_id === $this->user()->id, 404);

        return $ticket;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
