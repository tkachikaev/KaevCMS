<?php

namespace KaevCMS\Modules\SupportTickets\Http\Controllers;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminPathSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;
use KaevCMS\Modules\SupportTickets\Http\Requests\AdminSupportTicketMessageRequest;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessage;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketService;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;

final class AdminSupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets,
        private readonly SupportTicketSettings $settings,
        private readonly AdminPathSettings $adminPathSettings,
    ) {}

    public function index(Request $request): View
    {
        $validated = validator($request->query(), [
            'status' => ['nullable', Rule::enum(SupportTicketStatus::class)],
            'category' => ['nullable', Rule::enum(SupportTicketCategory::class)],
            'assigned' => ['nullable', Rule::in(['mine', 'unassigned'])],
            'q' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $admin = $this->admin($request);
        $query = SupportTicket::query()->with('assignedAdmin');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['category'])) {
            $query->where('category', $validated['category']);
        }
        if (($validated['assigned'] ?? null) === 'mine') {
            $query->where('assigned_admin_id', $admin->id);
        } elseif (($validated['assigned'] ?? null) === 'unassigned') {
            $query->whereNull('assigned_admin_id');
        }

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $numeric = preg_replace('/\D+/', '', $search);
            $query->where(function (Builder $builder) use ($search, $numeric): void {
                $builder->where('subject', 'like', '%'.$search.'%')
                    ->orWhere('user_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('user_email_snapshot', 'like', '%'.$search.'%');
                if ($numeric !== '') {
                    $builder->orWhereKey((int) $numeric);
                }
            });
        }

        return view('module-support-tickets::admin.index', [
            'tickets' => $query->latest('last_message_at')->paginate(25)->withQueryString(),
            'categories' => SupportTicketCategory::cases(),
            'statuses' => SupportTicketStatus::cases(),
            'filters' => $validated,
            'adminPath' => $this->adminPathSettings->path(),
            'counts' => [
                'new' => SupportTicket::query()->where('status', SupportTicketStatus::New)->count(),
                'in_progress' => SupportTicket::query()->where('status', SupportTicketStatus::InProgress)->count(),
                'awaiting_player' => SupportTicket::query()->where('status', SupportTicketStatus::AwaitingPlayer)->count(),
                'closed' => SupportTicket::query()->where('status', SupportTicketStatus::Closed)->count(),
            ],
        ]);
    }

    public function show(Request $request, SupportTicket $ticket): View
    {
        $this->admin($request);

        return view('module-support-tickets::admin.show', [
            'ticket' => $ticket,
            'adminPath' => $this->adminPathSettings->path(),
        ]);
    }

    public function assign(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->tickets->assignTo($ticket, $this->admin($request));

        return $this->backToTicket($ticket)->with('status', __('module-support-tickets::messages.ticket_assigned'));
    }

    public function reply(
        AdminSupportTicketMessageRequest $request,
        SupportTicket $ticket,
    ): RedirectResponse {
        $this->tickets->replyAsStaff($ticket, $this->admin($request), (string) $request->validated('body'));

        return $this->backToTicket($ticket)->with('status', __('module-support-tickets::messages.reply_sent'));
    }

    public function note(
        AdminSupportTicketMessageRequest $request,
        SupportTicket $ticket,
    ): RedirectResponse {
        $this->tickets->addInternalNote($ticket, $this->admin($request), (string) $request->validated('body'));

        return $this->backToTicket($ticket)->with('status', __('module-support-tickets::messages.internal_note_added'));
    }

    public function close(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->tickets->closeByStaff($ticket, $this->admin($request));

        return $this->backToTicket($ticket)->with('status', __('module-support-tickets::messages.ticket_closed'));
    }

    public function reopen(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->tickets->reopenByStaff($ticket, $this->admin($request));

        return $this->backToTicket($ticket)->with('status', __('module-support-tickets::messages.ticket_reopened'));
    }

    public function protection(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = validator($request->all(), ['protected' => ['required', 'boolean']])->validate();
        $this->tickets->setRetentionProtected($ticket, $this->admin($request), (bool) $validated['protected']);

        return $this->backToTicket($ticket)->with('status', __('module-support-tickets::messages.retention_protection_updated'));
    }

    public function editMessage(
        AdminSupportTicketMessageRequest $request,
        SupportTicket $ticket,
        SupportTicketMessage $message,
    ): RedirectResponse {
        abort_unless($message->ticket_id === $ticket->id, 404);
        $admin = $this->admin($request);
        abort_unless(
            $message->is_internal ? $this->canAddInternalNote($admin) : $this->canReply($admin),
            403,
        );
        $this->tickets->editStaffMessage($message, $admin, (string) $request->validated('body'));

        return $this->backToTicket($ticket)->with('status', __('module-support-tickets::messages.message_updated'));
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function canReply(Admin $admin): bool
    {
        return in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)
            || ($admin->role === AdminRole::Editor && $this->settings->editorCanReply());
    }

    private function canAddInternalNote(Admin $admin): bool
    {
        return in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)
            || ($admin->role === AdminRole::Editor && $this->settings->editorCanAddInternalNotes());
    }

    private function backToTicket(SupportTicket $ticket): RedirectResponse
    {
        return redirect()->route('admin.module-pages.support-tickets.show', [
            'adminPath' => $this->adminPathSettings->path(),
            'ticket' => $ticket,
        ]);
    }
}
