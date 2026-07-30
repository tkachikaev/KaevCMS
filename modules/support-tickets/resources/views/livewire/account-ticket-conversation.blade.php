<div>
    @if($notice)
        <div class="account-notice success support-live-notice" role="status">
            <span aria-hidden="true">✓</span><div>{{ $notice }}</div>
        </div>
    @endif

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

    <section class="account-section account-surface support-chat-card">
        <div class="account-section-heading"><div><h2>{{ __('module-support-tickets::messages.conversation') }}</h2></div></div>

        <div class="support-chat-window" data-support-conversation>
            @if($hasPreviousMessages)
                <div class="support-load-previous">
                    <button class="account-button secondary" type="button" wire:click="loadPrevious" wire:loading.attr="disabled" wire:target="loadPrevious">
                        ↑ {{ __('module-support-tickets::messages.show_previous_messages') }}
                    </button>
                </div>
            @endif

            <div class="support-conversation">
                @foreach($messages as $message)
                    <article wire:key="account-support-message-{{ $message->id }}" data-message-id="{{ $message->id }}" @class(['support-message', 'player' => $message->author_type === 'player', 'staff' => $message->author_type === 'admin'])>
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
        </div>

        @if($ticket->isClosed())
            <div class="account-notice warning support-chat-closed" role="status"><span aria-hidden="true">!</span><div>{{ __('module-support-tickets::messages.closed_ticket_notice') }}</div></div>
        @else
            <form class="support-reply-form support-chat-composer" wire:submit="reply" data-testid="player-reply-form">
                <label>
                    <span>{{ __('module-support-tickets::messages.message') }}</span>
                    <div class="account-field-control">
                        <textarea name="body" wire:model="body" rows="5" maxlength="{{ $messageMaxLength }}" placeholder="{{ __('module-support-tickets::messages.reply_placeholder') }}" data-character-input required></textarea>
                        <small class="support-character-counter" data-character-counter>0 / {{ $messageMaxLength }}</small>
                        @error('body')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                    </div>
                </label>
                <div class="account-form-actions support-chat-actions">
                    <button class="account-button secondary" type="button" wire:click="closeTicket" wire:confirm="{{ __('module-support-tickets::messages.close_ticket_confirm') }}">{{ __('module-support-tickets::messages.close_ticket') }}</button>
                    <button class="account-button primary" type="submit" wire:loading.attr="disabled" wire:target="reply">{{ __('module-support-tickets::messages.send_reply') }}</button>
                </div>
            </form>
        @endif
    </section>
</div>
