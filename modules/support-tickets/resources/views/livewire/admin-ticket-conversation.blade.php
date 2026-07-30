<div>
    @if($notice)
        <div class="notice notice-success support-live-notice" role="status"><p>{{ $notice }}</p></div>
    @endif

    <div class="content-toolbar support-ticket-admin-heading" data-testid="support-ticket-admin-heading">
        <div class="support-ticket-admin-heading-start">
            <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath]) }}">← {{ __('module-support-tickets::messages.back_to_tickets') }}</a>
        </div>
        <div class="support-ticket-admin-heading-center">
            <span class="support-ticket-status {{ $ticket->status->cssClass() }}">{{ $ticket->status->adminLabel() }}</span>
        </div>
        <div class="support-ticket-admin-heading-actions">
            @if($canReply)
                @if($ticket->assigned_admin_id !== $admin->id)
                    <button class="button button-secondary" type="button" wire:click="assignToMe" wire:loading.attr="disabled" wire:target="assignToMe">{{ __('module-support-tickets::messages.assign_to_me') }}</button>
                @endif
                @if($ticket->isClosed())
                    <button class="button button-primary" type="button" wire:click="reopenTicket" wire:loading.attr="disabled" wire:target="reopenTicket">{{ __('module-support-tickets::messages.reopen_ticket') }}</button>
                @else
                    <button class="button button-danger" type="button" wire:click="closeTicket" wire:confirm="{{ __('module-support-tickets::messages.close_ticket_confirm') }}" wire:loading.attr="disabled" wire:target="closeTicket">{{ __('module-support-tickets::messages.close_ticket') }}</button>
                @endif
            @endif
        </div>
    </div>

    <div class="support-admin-ticket-layout" data-testid="support-admin-ticket-layout">
        <main class="support-admin-ticket-main">
            <section class="form-card support-chat-card">
                <div class="settings-card-heading"><div><h2>{{ __('module-support-tickets::messages.conversation') }}</h2></div></div>

                <div class="support-chat-window admin-chat-window" data-support-conversation>
                    @if($hasPreviousMessages)
                        <div class="support-load-previous">
                            <button class="button button-secondary" type="button" wire:click="loadPrevious" wire:loading.attr="disabled" wire:target="loadPrevious">↑ {{ __('module-support-tickets::messages.show_previous_messages') }}</button>
                        </div>
                    @endif

                    <div class="support-conversation admin-conversation">
                        @foreach($messages as $message)
                            @php($canEditMessage = $message->is_internal ? $canAddInternalNote : $canReply)
                            <article wire:key="admin-support-message-{{ $message->id }}" data-message-id="{{ $message->id }}" @class(['support-message', 'player' => $message->author_type === 'player', 'staff' => $message->author_type === 'admin' && ! $message->is_internal, 'internal' => $message->is_internal])>
                                <header>
                                    <strong>{{ $message->author_name_snapshot }}</strong>
                                    <span>{{ $message->is_internal ? __('module-support-tickets::messages.internal_note') : ($message->author_type === 'player' ? __('module-support-tickets::messages.player') : __('module-support-tickets::messages.support_staff')) }}</span>
                                    <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('d.m.Y H:i') }}</time>
                                </header>

                                @if($editingMessageId === $message->id)
                                    <div class="support-message-editor support-message-editor-live">
                                        <textarea name="body" wire:model="editingBody" rows="5" maxlength="{{ $messageMaxLength }}" data-character-input required></textarea>
                                        <small class="support-character-counter" data-character-counter>0 / {{ $messageMaxLength }}</small>
                                        @error('editingBody')<small class="field-error" role="alert">{{ $message }}</small>@enderror
                                        <div class="support-inline-actions">
                                            <button class="button button-secondary" type="button" wire:click="cancelEditing">{{ __('module-support-tickets::messages.cancel') }}</button>
                                            <button class="button button-primary" type="button" wire:click="saveEditing" wire:loading.attr="disabled" wire:target="saveEditing">{{ __('module-support-tickets::messages.save_message') }}</button>
                                        </div>
                                    </div>
                                @else
                                    <div class="support-message-body">{!! nl2br(e($message->body)) !!}</div>
                                    @if($message->edited_at)<small class="support-message-edited">{{ __('module-support-tickets::messages.edited_at', ['date' => $message->edited_at->format('d.m.Y H:i')]) }}</small>@endif
                                    @if($canEditMessage && $message->isStaffMessage() && $message->admin_id === $admin->id)
                                        <button class="support-message-edit-button" type="button" wire:click="startEditing({{ $message->id }})">{{ __('module-support-tickets::messages.edit_message') }}</button>
                                    @endif
                                @endif

                                @if($canEditMessage && $message->revisions->isNotEmpty())
                                    <details class="support-message-revisions">
                                        <summary>{{ __('module-support-tickets::messages.revision_history') }} ({{ $message->revisions->count() }})</summary>
                                        @foreach($message->revisions as $revision)
                                            <div class="support-message-revision"><small>{{ $revision->editor_name_snapshot }} · {{ $revision->edited_at->format('d.m.Y H:i') }}</small><p>{!! nl2br(e($revision->previous_body)) !!}</p></div>
                                        @endforeach
                                    </details>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </div>

                @if($canReply)
                    <form class="support-staff-reply-form support-chat-composer" wire:submit="reply" data-testid="staff-reply-form">
                        <label>
                            <span>{{ __('module-support-tickets::messages.reply_to_player') }}</span>
                            <textarea name="body" wire:model="body" rows="5" maxlength="{{ $messageMaxLength }}" data-character-input required @disabled($ticket->isClosed())></textarea>
                            <small class="support-character-counter" data-character-counter>0 / {{ $messageMaxLength }}</small>
                            @error('body')<small class="field-error" role="alert">{{ $message }}</small>@enderror
                        </label>
                        <div class="settings-actions support-chat-actions">
                            @if($canAddInternalNote)
                                <button class="button button-secondary" type="button" wire:click="toggleNote" data-testid="internal-note-toggle">+ {{ __('module-support-tickets::messages.add_internal_note') }}</button>
                            @endif
                            <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="reply" @disabled($ticket->isClosed())>{{ __('module-support-tickets::messages.send_reply') }}</button>
                        </div>
                    </form>
                @endif

                @if($canAddInternalNote && $noteOpen)
                    <form class="support-internal-note-form support-inline-note-form" wire:submit="addNote" data-testid="internal-note-form">
                        <div class="support-inline-note-heading">
                            <div><strong>{{ __('module-support-tickets::messages.add_internal_note') }}</strong><small>{{ __('module-support-tickets::messages.internal_note_help') }}</small></div>
                            <button class="support-note-close" type="button" wire:click="toggleNote" aria-label="{{ __('module-support-tickets::messages.cancel') }}">×</button>
                        </div>
                        <label>
                            <span>{{ __('module-support-tickets::messages.message') }}</span>
                            <textarea name="note_body" wire:model="noteBody" rows="4" maxlength="{{ $messageMaxLength }}" data-character-input required></textarea>
                            <small class="support-character-counter" data-character-counter>0 / {{ $messageMaxLength }}</small>
                            @error('noteBody')<small class="field-error" role="alert">{{ $message }}</small>@enderror
                        </label>
                        <div class="settings-actions"><button class="button button-secondary" type="submit" wire:loading.attr="disabled" wire:target="addNote">{{ __('module-support-tickets::messages.add_internal_note') }}</button></div>
                    </form>
                @endif
            </section>
        </main>

        <aside class="support-admin-ticket-side">
            <section class="form-card support-ticket-details-card">
                <div class="settings-card-heading"><div><h2>{{ __('module-support-tickets::messages.ticket_details') }}</h2></div></div>
                <dl class="support-ticket-detail-list">
                    <div><dt>{{ __('module-support-tickets::messages.player_name') }}</dt><dd>{{ $ticket->user_name_snapshot }}</dd></div>
                    <div><dt>{{ __('module-support-tickets::messages.email') }}</dt><dd>{{ $ticket->user_email_snapshot }}</dd></div>
                    <div><dt>{{ __('module-support-tickets::messages.category') }}</dt><dd>{{ $ticket->category->label() }}</dd></div>
                    <div><dt>{{ __('module-support-tickets::messages.assigned_to') }}</dt><dd>{{ $ticket->assignedAdmin?->name ?? __('module-support-tickets::messages.not_assigned') }}</dd></div>
                    <div><dt>{{ __('module-support-tickets::messages.created_at') }}</dt><dd>{{ $ticket->created_at->format('d.m.Y H:i') }}</dd></div>
                    <div><dt>{{ __('module-support-tickets::messages.last_activity') }}</dt><dd>{{ $ticket->last_message_at->format('d.m.Y H:i') }}</dd></div>
                </dl>
                @if($canReply)
                    <div class="support-retention-protection">
                        <button class="button button-secondary" type="button" wire:click="toggleRetentionProtection" wire:loading.attr="disabled" wire:target="toggleRetentionProtection">
                            {{ $ticket->retention_protected ? __('module-support-tickets::messages.remove_retention_protection') : __('module-support-tickets::messages.enable_retention_protection') }}
                        </button>
                        @if($ticket->retention_protected)<small>{{ __('module-support-tickets::messages.retention_protected_notice') }}</small>@endif
                    </div>
                @endif
            </section>
        </aside>
    </div>
</div>
