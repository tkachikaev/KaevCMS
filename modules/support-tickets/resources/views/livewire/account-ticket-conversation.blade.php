{{-- Player ticket as a chat: a compact header, the conversation grouped by day with the
     player on the right, a messenger-style composer and the ticket details beside it. --}}
<div class="account-page support-thread" data-testid="support-thread">
    @if($notice)
        <div class="account-notice success support-live-notice" role="status">
            <span aria-hidden="true">✓</span><div>{{ $notice }}</div>
        </div>
    @endif

    <header class="support-thread-head">
        <a wire:navigate class="support-thread-back" href="{{ route('modules.support-tickets.index') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            {{ __('module-support-tickets::messages.back_to_tickets') }}
        </a>
        <div class="support-thread-summary">
            <span class="support-thread-number">{{ $ticket->number() }}</span>
            <span class="support-ticket-status {{ $ticket->status->cssClass() }}"><i aria-hidden="true"></i>{{ $ticket->status->label() }}</span>
            <span class="support-thread-category">{{ $ticket->category->label() }}</span>
        </div>
        @unless($ticket->isClosed())
            <button class="support-thread-close" type="button" wire:click="closeTicket" wire:confirm="{{ __('module-support-tickets::messages.close_ticket_confirm') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                {{ __('module-support-tickets::messages.close_ticket') }}
            </button>
        @endunless
    </header>
    @error('close')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror

    <div class="support-thread-layout">
        <section class="support-thread-chat" aria-label="{{ __('module-support-tickets::messages.conversation') }}">
            <div class="support-chat-window" data-support-conversation>
                @if($hasPreviousMessages)
                    <div class="support-load-previous">
                        <button type="button" wire:click="loadPrevious" wire:loading.attr="disabled" wire:target="loadPrevious">
                            ↑ {{ __('module-support-tickets::messages.show_previous_messages') }}
                        </button>
                    </div>
                @endif

                <div class="support-conversation">
                    @php $previousDay = null; @endphp
                    @foreach($messages as $message)
                        @php
                            $day = $message->created_at->toDateString();
                            $isPlayer = $message->author_type === 'player';
                        @endphp
                        @if($day !== $previousDay)
                            <div class="support-day" role="separator">
                                <span>{{ $message->created_at->isToday() ? __('module-support-tickets::messages.today') : ($message->created_at->isYesterday() ? __('module-support-tickets::messages.yesterday') : $message->created_at->translatedFormat('j F Y')) }}</span>
                            </div>
                            @php $previousDay = $day; @endphp
                        @endif
                        <article wire:key="account-support-message-{{ $message->id }}" data-message-id="{{ $message->id }}" @class(['support-message', 'player' => $isPlayer, 'staff' => ! $isPlayer])>
                            <span class="support-message-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $message->author_name_snapshot, 0, 1)) }}</span>
                            <div class="support-message-bubble">
                                <header>
                                    <strong>{{ $isPlayer ? __('module-support-tickets::messages.you') : $message->author_name_snapshot }}</strong>
                                    @unless($isPlayer)<span class="support-message-role">{{ __('module-support-tickets::messages.support_team') }}</span>@endunless
                                </header>
                                <div class="support-message-body">{!! nl2br(e($message->body)) !!}</div>
                                <footer>
                                    <time datetime="{{ $message->created_at->toIso8601String() }}" title="{{ $message->created_at->format('d.m.Y H:i') }}">{{ $message->created_at->format('H:i') }}</time>
                                    @if($message->edited_at)
                                        <small class="support-message-edited">· {{ __('module-support-tickets::messages.edited_at', ['date' => $message->edited_at->format('d.m.Y H:i')]) }}</small>
                                    @endif
                                </footer>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>

            @if($ticket->isClosed())
                <div class="account-notice warning support-chat-closed" role="status"><span aria-hidden="true">!</span><div>{{ __('module-support-tickets::messages.closed_ticket_notice') }}</div></div>
            @else
                <form class="support-reply-form support-chat-composer" wire:submit="reply" data-testid="player-reply-form" data-support-composer>
                    <label class="support-composer-field">
                        <span class="sr-only">{{ __('module-support-tickets::messages.message') }}</span>
                        <textarea name="body" wire:model="body" rows="1" maxlength="{{ $messageMaxLength }}" placeholder="{{ __('module-support-tickets::messages.reply_placeholder') }}" data-character-input data-autogrow required></textarea>
                        <button class="support-composer-send" type="submit" wire:loading.attr="disabled" wire:loading.class="is-sending" wire:target="reply" aria-label="{{ __('module-support-tickets::messages.send_reply') }}" title="{{ __('module-support-tickets::messages.send_reply') }}">
                            <svg class="support-send-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/></svg>
                            <span class="support-send-spinner" aria-hidden="true"></span>
                        </button>
                    </label>
                    <div class="support-composer-meta">
                        <span>{{ __('module-support-tickets::messages.send_shortcut') }}</span>
                        <small class="support-character-counter" data-character-counter>0 / {{ $messageMaxLength }}</small>
                    </div>
                    @error('body')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                </form>
            @endif
        </section>

        <aside class="support-thread-details">
            <h2>{{ __('module-support-tickets::messages.ticket_details') }}</h2>
            <dl>
                <div><dt>{{ __('module-support-tickets::messages.category') }}</dt><dd>{{ $ticket->category->label() }}</dd></div>
                <div><dt>{{ __('module-support-tickets::messages.assigned_to') }}</dt><dd>{{ $ticket->assignedAdmin?->name ?? __('module-support-tickets::messages.not_assigned') }}</dd></div>
                <div><dt>{{ __('module-support-tickets::messages.created_at') }}</dt><dd>{{ $ticket->created_at->format('d.m.Y H:i') }}</dd></div>
                <div><dt>{{ __('module-support-tickets::messages.updated_at') }}</dt><dd>{{ $ticket->last_message_at->format('d.m.Y H:i') }}</dd></div>
            </dl>
        </aside>
    </div>
</div>
