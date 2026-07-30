<?php

namespace KaevCMS\Modules\SupportTickets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Http\Requests\CreateSupportTicketRequest;
use KaevCMS\Modules\SupportTickets\Http\Requests\PlayerSupportTicketReplyRequest;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketService;

final class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('module-support-tickets::account.index', ['user' => $user]);
    }

    public function store(CreateSupportTicketRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $ticket = $this->tickets->create(
            user: $user,
            category: SupportTicketCategory::from((string) $request->validated('category')),
            subject: (string) $request->validated('subject'),
            body: (string) $request->validated('body'),
        );

        return redirect()->route('modules.support-tickets.show', $ticket)
            ->with('status', __('module-support-tickets::messages.ticket_created', ['number' => $ticket->number()]));
    }

    public function show(Request $request, SupportTicket $ticket): View
    {
        $user = $this->user($request);
        $this->assertOwner($ticket, $user);

        return view('module-support-tickets::account.show', [
            'ticket' => $ticket,
            'user' => $user,
        ]);
    }

    public function reply(
        PlayerSupportTicketReplyRequest $request,
        SupportTicket $ticket,
    ): RedirectResponse {
        $user = $this->user($request);
        $this->assertOwner($ticket, $user);
        $this->tickets->replyAsPlayer($ticket, $user, (string) $request->validated('body'));

        return redirect()->route('modules.support-tickets.show', $ticket)
            ->with('status', __('module-support-tickets::messages.reply_sent'));
    }

    public function close(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $user = $this->user($request);
        $this->assertOwner($ticket, $user);
        $this->tickets->closeByPlayer($ticket, $user);

        return redirect()->route('modules.support-tickets.show', $ticket)
            ->with('status', __('module-support-tickets::messages.ticket_closed'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function assertOwner(SupportTicket $ticket, User $user): void
    {
        abort_unless($ticket->user_id === $user->id, 404);
    }
}
