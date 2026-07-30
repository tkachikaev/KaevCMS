<?php

namespace KaevCMS\Modules\SupportTickets\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketService;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;
use Livewire\Component;
use Livewire\WithPagination;

final class AccountTicketIndex extends Component
{
    use WithPagination;

    public bool $creating = false;

    public string $category = '';

    public string $subject = '';

    public string $body = '';

    public function mount(): void
    {
        $this->user();
        $this->category = SupportTicketCategory::Gameplay->value;
    }

    public function hydrate(): void
    {
        $this->user();
    }

    public function openCreateForm(): void
    {
        $this->resetValidation();
        $this->creating = true;
    }

    public function closeCreateForm(): void
    {
        $this->resetValidation();
        $this->reset(['subject', 'body']);
        $this->category = SupportTicketCategory::Gameplay->value;
        $this->creating = false;
    }

    public function createTicket(SupportTicketService $tickets): void
    {
        $user = $this->user();
        $rateLimitKey = 'support-ticket-create:'.$user->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $this->addError('subject', __('module-support-tickets::messages.rate_limit_retry', [
                'seconds' => RateLimiter::availableIn($rateLimitKey),
            ]));

            return;
        }

        $settings = app(SupportTicketSettings::class);
        $this->subject = trim($this->subject);
        $this->body = trim($this->body);
        $validated = $this->validate([
            'category' => ['required', Rule::enum(SupportTicketCategory::class)],
            'subject' => ['required', 'string', 'min:3', 'max:'.$settings->subjectMaxLength()],
            'body' => ['required', 'string', 'min:3', 'max:'.$settings->initialMessageMaxLength()],
        ], attributes: [
            'category' => __('module-support-tickets::messages.category'),
            'subject' => __('module-support-tickets::messages.subject'),
            'body' => __('module-support-tickets::messages.message'),
        ]);

        RateLimiter::hit($rateLimitKey, 600);

        $ticket = $tickets->create(
            user: $user,
            category: SupportTicketCategory::from((string) $validated['category']),
            subject: (string) $validated['subject'],
            body: (string) $validated['body'],
        );

        session()->flash('status', __('module-support-tickets::messages.ticket_created', [
            'number' => $ticket->number(),
        ]));
        $this->redirectRoute('modules.support-tickets.show', ['ticket' => $ticket], navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $settings = app(SupportTicketSettings::class);

        return view('module-support-tickets::livewire.account-ticket-index', [
            'tickets' => SupportTicket::query()
                ->where('user_id', $user->id)
                ->latest('last_message_at')
                ->paginate(15, ['*'], 'ticketsPage'),
            'categories' => SupportTicketCategory::cases(),
            'openCount' => SupportTicket::query()->where('user_id', $user->id)->open()->count(),
            'limits' => [
                'max_open_tickets_per_user' => $settings->maxOpenTicketsPerUser(),
                'subject_max_length' => $settings->subjectMaxLength(),
                'initial_message_max_length' => $settings->initialMessageMaxLength(),
            ],
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
