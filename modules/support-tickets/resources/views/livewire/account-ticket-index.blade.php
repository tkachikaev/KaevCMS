<div>
    <section class="account-section account-surface">
        <div class="account-section-heading support-ticket-list-heading">
            <div>
                <span class="account-eyebrow">{{ __('module-support-tickets::messages.navigation_label') }}</span>
                <h1>{{ __('module-support-tickets::messages.my_tickets') }}</h1>
                <p>{{ __('module-support-tickets::messages.my_tickets_help') }}</p>
            </div>
            <div class="support-ticket-heading-actions">
                <span class="support-ticket-open-count">{{ $openCount }} / {{ $limits['max_open_tickets_per_user'] }}</span>
                <button class="account-button primary support-create-ticket-button" type="button" wire:click="openCreateForm" @disabled($creating || $openCount >= $limits['max_open_tickets_per_user'])>
                    + {{ __('module-support-tickets::messages.create_ticket') }}
                </button>
            </div>
        </div>

        @if($creating)
            <form class="account-form-card support-ticket-form support-ticket-create-panel" wire:submit="createTicket" data-testid="support-ticket-create-form" novalidate>
                <div class="account-form-title">
                    <span aria-hidden="true">?</span>
                    <div>
                        <h2>{{ __('module-support-tickets::messages.new_ticket') }}</h2>
                        <p>{{ __('module-support-tickets::messages.new_ticket_help') }}</p>
                    </div>
                </div>
                <label>
                    <span>{{ __('module-support-tickets::messages.category') }}</span>
                    <div class="account-field-control">
                        <select name="category" wire:model="category" required>
                            @foreach($categories as $categoryOption)
                                <option value="{{ $categoryOption->value }}">{{ $categoryOption->label() }}</option>
                            @endforeach
                        </select>
                        @error('category')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                    </div>
                </label>
                <label>
                    <span>{{ __('module-support-tickets::messages.subject') }}</span>
                    <div class="account-field-control">
                        <input
                            name="subject"
                            wire:model="subject"
                            type="text"
                            minlength="3"
                            maxlength="{{ $limits['subject_max_length'] }}"
                            placeholder="{{ __('module-support-tickets::messages.subject_placeholder') }}"
                            data-character-input
                            required
                        >
                        <small class="support-character-counter" data-character-counter>0 / {{ $limits['subject_max_length'] }}</small>
                        @error('subject')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                    </div>
                </label>
                <label>
                    <span>{{ __('module-support-tickets::messages.message') }}</span>
                    <div class="account-field-control">
                        <textarea
                            name="body"
                            wire:model="body"
                            rows="8"
                            minlength="3"
                            maxlength="{{ $limits['initial_message_max_length'] }}"
                            placeholder="{{ __('module-support-tickets::messages.initial_message_placeholder') }}"
                            data-character-input
                            required
                        ></textarea>
                        <small class="support-character-counter" data-character-counter>0 / {{ $limits['initial_message_max_length'] }}</small>
                        @error('body')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                    </div>
                </label>
                <div class="account-form-actions support-ticket-create-actions">
                    <button class="account-button secondary" type="button" wire:click="closeCreateForm">{{ __('module-support-tickets::messages.cancel') }}</button>
                    <button class="account-button primary" type="submit" wire:loading.attr="disabled" wire:target="createTicket">
                        {{ __('module-support-tickets::messages.create_ticket') }}
                    </button>
                </div>
            </form>
        @endif

        @if($tickets->isEmpty())
            <div class="account-empty support-ticket-empty">
                <span class="account-empty-symbol" aria-hidden="true">?</span>
                <h2>{{ __('module-support-tickets::messages.no_tickets_title') }}</h2>
                <p>{{ __('module-support-tickets::messages.no_tickets_description') }}</p>
            </div>
        @else
            <div class="support-ticket-list">
                @foreach($tickets as $ticket)
                    <a wire:navigate class="support-ticket-row" href="{{ route('modules.support-tickets.show', $ticket) }}">
                        <div class="support-ticket-row-main">
                            <small>{{ $ticket->number() }} · {{ $ticket->category->label() }}</small>
                            <strong>{{ $ticket->subject }}</strong>
                            <span>{{ __('module-support-tickets::messages.updated_at') }}: {{ $ticket->last_message_at->format('d.m.Y H:i') }}</span>
                        </div>
                        <span class="support-ticket-status {{ $ticket->status->cssClass() }}">{{ $ticket->status->label() }}</span>
                    </a>
                @endforeach
            </div>

            @if($tickets->hasPages())
                <nav class="support-pagination" aria-label="{{ __('module-support-tickets::messages.pagination') }}">
                    <button class="account-button secondary" type="button" wire:click="previousPage('ticketsPage')" @disabled($tickets->onFirstPage())>← {{ __('module-support-tickets::messages.previous') }}</button>
                    <span>{{ __('module-support-tickets::messages.page_of', ['current' => $tickets->currentPage(), 'last' => $tickets->lastPage()]) }}</span>
                    <button class="account-button secondary" type="button" wire:click="nextPage('ticketsPage')" @disabled(! $tickets->hasMorePages())>{{ __('module-support-tickets::messages.next') }} →</button>
                </nav>
            @endif
        @endif
    </section>
</div>
