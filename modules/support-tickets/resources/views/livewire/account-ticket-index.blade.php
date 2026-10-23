<div class="account-page">
    <x-account-panel class="account-section account-surface">
        <x-account-panel-header class="support-ticket-list-heading">
            <x-slot:summary><span class="support-ticket-open-count">{{ $openCount }} / {{ $limits['max_open_tickets_per_user'] }}</span></x-slot:summary>
            <x-slot:actions><button class="account-button primary support-create-ticket-button" type="button" wire:click="openCreateForm" @disabled($creating || $openCount >= $limits['max_open_tickets_per_user'])>+ {{ __('module-support-tickets::messages.create_ticket') }}</button></x-slot:actions>
        </x-account-panel-header>

        @if($creating)
            {{-- New ticket: category tiles, fields styled like the chat composer and short tips beside them. --}}
            @php
                $categoryIcons = [
                    'gameplay' => 'M6 11h4M8 9v4M15 12h.01M18 10h.01M17.32 5H6.68a4 4 0 0 0-3.98 3.59L2 15a3 3 0 0 0 5.2 2.04L9 15h6l1.8 2.04A3 3 0 0 0 22 15l-.7-6.41A4 4 0 0 0 17.32 5z',
                    'game_account' => 'M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10z',
                    'technical_problem' => 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z',
                    'website_error' => 'M4 4h16a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zM3 9h18M12 12v3M12 18h.01',
                    'donations_and_bonuses' => 'M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z',
                    'complaint' => 'M4 22V4M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1',
                    'other' => 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2zM8 10h.01M12 10h.01M16 10h.01',
                ];
            @endphp
            <form class="support-new support-ticket-create-panel" wire:submit="createTicket" data-testid="support-ticket-create-form" novalidate>
                <header class="support-new-head">
                    <div>
                        <h2>{{ __('module-support-tickets::messages.new_ticket') }}</h2>
                        <p>{{ __('module-support-tickets::messages.new_ticket_lead') }}</p>
                    </div>
                </header>

                <div class="support-new-layout">
                    <div class="support-new-fields">
                        <fieldset class="support-new-categories">
                            <legend class="support-new-label">{{ __('module-support-tickets::messages.category') }}</legend>
                            <div class="support-category-grid">
                                @foreach($categories as $categoryOption)
                                    <label class="support-category-chip">
                                        <input type="radio" name="category" value="{{ $categoryOption->value }}" wire:model="category">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $categoryIcons[$categoryOption->value] ?? $categoryIcons['other'] }}"/></svg>
                                        <span>{{ $categoryOption->label() }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('category')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                        </fieldset>

                        <label class="support-new-field">
                            <span class="support-new-label">{{ __('module-support-tickets::messages.subject') }}</span>
                            <span class="support-new-input">
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
                            </span>
                            @error('subject')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                        </label>

                        <label class="support-new-field">
                            <span class="support-new-label">{{ __('module-support-tickets::messages.message') }}</span>
                            <span class="support-new-input is-multiline">
                                <textarea
                                    name="body"
                                    wire:model="body"
                                    rows="5"
                                    minlength="3"
                                    maxlength="{{ $limits['initial_message_max_length'] }}"
                                    placeholder="{{ __('module-support-tickets::messages.initial_message_placeholder') }}"
                                    data-character-input
                                    data-autogrow
                                    data-autogrow-max="380"
                                    required
                                ></textarea>
                                <small class="support-character-counter" data-character-counter>0 / {{ $limits['initial_message_max_length'] }}</small>
                            </span>
                            @error('body')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                        </label>
                    </div>

                    <aside class="support-new-tips">
                        <h3>{{ __('module-support-tickets::messages.tips_title') }}</h3>
                        <ol>
                            <li>{{ __('module-support-tickets::messages.tip_character') }}</li>
                            <li>{{ __('module-support-tickets::messages.tip_time') }}</li>
                            <li>{{ __('module-support-tickets::messages.tip_expected') }}</li>
                        </ol>
                        <p class="support-new-security">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4"/></svg>
                            <span>{{ __('module-support-tickets::messages.security_note') }}</span>
                        </p>
                    </aside>
                </div>

                <footer class="support-new-actions">
                    <span class="support-new-shortcut">{{ __('module-support-tickets::messages.send_shortcut') }}</span>
                    <button class="support-new-cancel" type="button" wire:click="closeCreateForm">{{ __('module-support-tickets::messages.cancel') }}</button>
                    <button class="support-new-submit" type="submit" wire:loading.attr="disabled" wire:loading.class="is-sending" wire:target="createTicket">
                        <span>{{ __('module-support-tickets::messages.create_ticket') }}</span>
                        <svg class="support-send-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        <span class="support-send-spinner" aria-hidden="true"></span>
                    </button>
                </footer>
            </form>
        @endif

        @if($tickets->isEmpty())
            <x-account-empty class="account-empty support-ticket-empty">
                <span class="account-empty-symbol" aria-hidden="true">?</span>
                <h2>{{ __('module-support-tickets::messages.no_tickets_title') }}</h2>
                <p>{{ __('module-support-tickets::messages.no_tickets_description') }}</p>
            </x-account-empty>
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
    </x-account-panel>
</div>
