@extends('admin.layouts.panel')
@section('title', $ticket->subject)
@section('description', __('module-support-tickets::messages.ticket_number', ['number' => $ticket->number()]))
@section('content')
@php($adminPath = request()->route('adminPath'))
<div class="content-toolbar support-ticket-admin-heading">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath]) }}">← {{ __('module-support-tickets::messages.back_to_tickets') }}</a>
    <span class="support-ticket-status {{ $ticket->status->cssClass() }}">{{ $ticket->status->adminLabel() }}</span>
    @if($canManage)
        @if($ticket->assigned_admin_id !== $admin->id)
            <form method="POST" action="{{ route('admin.module-pages.support-tickets.assign', ['adminPath' => $adminPath, 'ticket' => $ticket]) }}">@csrf @method('PATCH')<button class="button button-secondary" type="submit">{{ __('module-support-tickets::messages.assign_to_me') }}</button></form>
        @endif
        @if($ticket->isClosed())
            <form method="POST" action="{{ route('admin.module-pages.support-tickets.reopen', ['adminPath' => $adminPath, 'ticket' => $ticket]) }}">@csrf @method('PATCH')<button class="button button-primary" type="submit">{{ __('module-support-tickets::messages.reopen_ticket') }}</button></form>
        @else
            <form method="POST" action="{{ route('admin.module-pages.support-tickets.close', ['adminPath' => $adminPath, 'ticket' => $ticket]) }}" data-confirm="{{ __('module-support-tickets::messages.close_ticket_confirm') }}">@csrf @method('PATCH')<button class="button button-danger" type="submit">{{ __('module-support-tickets::messages.close_ticket') }}</button></form>
        @endif
    @endif
</div>

<section class="settings-card">
    <div class="settings-card-heading"><div><h2>{{ __('module-support-tickets::messages.ticket_details') }}</h2></div></div>
    <div class="support-ticket-meta-grid admin-meta">
        <div><span>{{ __('module-support-tickets::messages.player_name') }}</span><strong>{{ $ticket->user_name_snapshot }}</strong></div>
        <div><span>{{ __('module-support-tickets::messages.email') }}</span><strong>{{ $ticket->user_email_snapshot }}</strong></div>
        <div><span>{{ __('module-support-tickets::messages.category') }}</span><strong>{{ $ticket->category->label() }}</strong></div>
        <div><span>{{ __('module-support-tickets::messages.assigned_to') }}</span><strong>{{ $ticket->assignedAdmin?->name ?? __('module-support-tickets::messages.not_assigned') }}</strong></div>
        <div><span>{{ __('module-support-tickets::messages.created_at') }}</span><strong>{{ $ticket->created_at->format('d.m.Y H:i') }}</strong></div>
        <div><span>{{ __('module-support-tickets::messages.last_activity') }}</span><strong>{{ $ticket->last_message_at->format('d.m.Y H:i') }}</strong></div>
    </div>
</section>

<section class="settings-card">
    <div class="settings-card-heading"><div><h2>{{ __('module-support-tickets::messages.conversation') }}</h2></div></div>
    <div class="support-conversation admin-conversation" data-support-conversation>
        @foreach($ticket->messages as $message)
            <article @class(['support-message', 'player' => $message->author_type === 'player', 'staff' => $message->author_type === 'admin' && ! $message->is_internal, 'internal' => $message->is_internal])>
                <header><strong>{{ $message->author_name_snapshot }}</strong><span>{{ $message->is_internal ? __('module-support-tickets::messages.internal_note') : ($message->author_type === 'player' ? __('module-support-tickets::messages.player') : __('module-support-tickets::messages.support_staff')) }}</span><time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('d.m.Y H:i') }}</time></header>
                <div class="support-message-body">{!! nl2br(e($message->body)) !!}</div>
                @if($message->edited_at)<small class="support-message-edited">{{ __('module-support-tickets::messages.edited_at', ['date' => $message->edited_at->format('d.m.Y H:i')]) }}</small>@endif
                @if($canManage && $message->isStaffMessage() && $message->admin_id === $admin->id)
                    <details class="support-message-editor"><summary>{{ __('module-support-tickets::messages.edit_message') }}</summary><form method="POST" action="{{ route('admin.module-pages.support-tickets.messages.update', ['adminPath' => $adminPath, 'ticket' => $ticket, 'message' => $message]) }}">@csrf @method('PUT')<textarea name="body" rows="5" maxlength="{{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX }}" data-character-input required>{{ $message->body }}</textarea><small class="support-character-counter" data-character-counter>0 / {{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX }}</small><button class="button button-primary" type="submit">{{ __('module-support-tickets::messages.save_message') }}</button></form></details>
                @endif
                @if($canManage && $message->revisions->isNotEmpty())
                    <details class="support-message-revisions"><summary>{{ __('module-support-tickets::messages.revision_history') }} ({{ $message->revisions->count() }})</summary>@foreach($message->revisions as $revision)<div class="support-message-revision"><small>{{ $revision->editor_name_snapshot }} · {{ $revision->edited_at->format('d.m.Y H:i') }}</small><p>{!! nl2br(e($revision->previous_body)) !!}</p></div>@endforeach</details>
                @endif
            </article>
        @endforeach
    </div>
</section>

@if($canManage)
<section class="support-staff-composer-grid">
    <form class="settings-card" method="POST" action="{{ route('admin.module-pages.support-tickets.reply', ['adminPath' => $adminPath, 'ticket' => $ticket]) }}">
        @csrf
        <div class="settings-card-heading"><div><h2>{{ __('module-support-tickets::messages.reply_to_player') }}</h2><p>{{ __('module-support-tickets::messages.reply_to_player_help') }}</p></div></div>
        <label><span>{{ __('module-support-tickets::messages.message') }}</span><textarea name="body" rows="7" maxlength="{{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX }}" data-character-input required></textarea><small class="support-character-counter" data-character-counter>0 / {{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX }}</small></label>
        <div class="settings-actions"><button class="button button-primary" type="submit" @disabled($ticket->isClosed())>{{ __('module-support-tickets::messages.send_reply') }}</button></div>
    </form>
    <form class="settings-card support-internal-note-form" method="POST" action="{{ route('admin.module-pages.support-tickets.note', ['adminPath' => $adminPath, 'ticket' => $ticket]) }}">
        @csrf
        <div class="settings-card-heading"><div><h2>{{ __('module-support-tickets::messages.add_internal_note') }}</h2><p>{{ __('module-support-tickets::messages.internal_note_help') }}</p></div></div>
        <label><span>{{ __('module-support-tickets::messages.message') }}</span><textarea name="body" rows="7" maxlength="{{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX }}" data-character-input required></textarea><small class="support-character-counter" data-character-counter>0 / {{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX }}</small></label>
        <div class="settings-actions"><button class="button button-secondary" type="submit">{{ __('module-support-tickets::messages.add_internal_note') }}</button></div>
    </form>
</section>
@endif
@endsection
@push('head')<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>@endpush
@push('scripts')<script src="{{ asset('assets/modules/support-tickets.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>@endpush
