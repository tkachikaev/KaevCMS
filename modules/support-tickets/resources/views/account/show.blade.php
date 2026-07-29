@extends('account-theme::layouts.app')
@section('title', $ticket->subject)
@section('content')
<section class="account-section account-surface support-ticket-detail">
    <div class="account-section-heading support-ticket-heading">
        <div>
            <a wire:navigate class="support-ticket-back" href="{{ route('modules.support-tickets.index') }}">← {{ __('module-support-tickets::messages.back_to_tickets') }}</a>
            <span class="account-eyebrow">{{ __('module-support-tickets::messages.ticket_number', ['number' => $ticket->number()]) }}</span>
            <h1>{{ $ticket->subject }}</h1>
            <p>{{ $ticket->category->label() }}</p>
        </div>
        <span class="support-ticket-status {{ $ticket->status->cssClass() }}">{{ $ticket->status->label() }}</span>
    </div>

    <div class="support-ticket-meta-grid">
        <div><span>{{ __('module-support-tickets::messages.created_at') }}</span><strong>{{ $ticket->created_at->format('d.m.Y H:i') }}</strong></div>
        <div><span>{{ __('module-support-tickets::messages.updated_at') }}</span><strong>{{ $ticket->last_message_at->format('d.m.Y H:i') }}</strong></div>
        <div><span>{{ __('module-support-tickets::messages.assigned_to') }}</span><strong>{{ $ticket->assignedAdmin?->name ?? __('module-support-tickets::messages.not_assigned') }}</strong></div>
    </div>
</section>

<section class="account-section account-surface">
    <div class="account-section-heading"><div><h2>{{ __('module-support-tickets::messages.conversation') }}</h2></div></div>
    <div class="support-conversation" data-support-conversation>
        @foreach($ticket->messages as $message)
            <article @class(['support-message', 'player' => $message->author_type === 'player', 'staff' => $message->author_type === 'admin'])>
                <header>
                    <strong>{{ $message->author_name_snapshot }}</strong>
                    <span>{{ $message->author_type === 'player' ? __('module-support-tickets::messages.player') : __('module-support-tickets::messages.support_staff') }}</span>
                    <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('d.m.Y H:i') }}</time>
                </header>
                <div class="support-message-body">{!! nl2br(e($message->body)) !!}</div>
                @if($message->edited_at)
                    <small class="support-message-edited">{{ __('module-support-tickets::messages.edited_at', ['date' => $message->edited_at->format('d.m.Y H:i')]) }}</small>
                @endif
            </article>
        @endforeach
    </div>
</section>

<section class="account-section account-surface">
    @if($ticket->isClosed())
        <div class="account-notice warning" role="status"><span aria-hidden="true">!</span><div>{{ __('module-support-tickets::messages.closed_ticket_notice') }}</div></div>
    @else
        <form class="support-reply-form" method="POST" action="{{ route('modules.support-tickets.reply', $ticket) }}">
            @csrf
            <label>
                <span>{{ __('module-support-tickets::messages.message') }}</span>
                <div class="account-field-control">
                    <textarea name="body" rows="6" maxlength="{{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX }}" placeholder="{{ __('module-support-tickets::messages.reply_placeholder') }}" data-character-input required>{{ old('body') }}</textarea>
                    <small class="support-character-counter" data-character-counter>0 / {{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX }}</small>
                    @error('body')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                </div>
            </label>
            <div class="account-form-actions">
                <button class="account-button primary" type="submit">{{ __('module-support-tickets::messages.send_reply') }}</button>
            </div>
        </form>
        <form method="POST" action="{{ route('modules.support-tickets.close', $ticket) }}" data-confirm="{{ __('module-support-tickets::messages.close_ticket_confirm') }}">
            @csrf
            @method('PATCH')
            <button class="account-button secondary" type="submit">{{ __('module-support-tickets::messages.close_ticket') }}</button>
        </form>
    @endif
</section>
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@push('scripts')
<script src="{{ asset('assets/modules/support-tickets.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
