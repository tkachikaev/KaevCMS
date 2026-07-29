@extends('account-theme::layouts.app')
@section('title', __('module-support-tickets::messages.account_title'))
@section('content')
<section class="account-section account-surface support-ticket-create">
    <div class="account-section-heading">
        <div>
            <span class="account-eyebrow">{{ __('module-support-tickets::messages.navigation_label') }}</span>
            <h1>{{ __('module-support-tickets::messages.account_title') }}</h1>
            <p>{{ __('module-support-tickets::messages.account_description') }}</p>
        </div>
        <span class="support-ticket-open-count">{{ $openCount }} / {{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MAX_OPEN_TICKETS_PER_USER }}</span>
    </div>

    <form class="account-form-card support-ticket-form" method="POST" action="{{ route('modules.support-tickets.store') }}">
        @csrf
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
                <select name="category" required>
                    @foreach($categories as $category)
                        <option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ $category->label() }}</option>
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
                    type="text"
                    minlength="3"
                    maxlength="{{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::SUBJECT_MAX }}"
                    value="{{ old('subject') }}"
                    placeholder="{{ __('module-support-tickets::messages.subject_placeholder') }}"
                    data-character-input
                    required
                >
                <small class="support-character-counter" data-character-counter>0 / {{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::SUBJECT_MAX }}</small>
                @error('subject')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
            </div>
        </label>
        <label>
            <span>{{ __('module-support-tickets::messages.message') }}</span>
            <div class="account-field-control">
                <textarea
                    name="body"
                    rows="8"
                    minlength="3"
                    maxlength="{{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::INITIAL_MESSAGE_MAX }}"
                    placeholder="{{ __('module-support-tickets::messages.initial_message_placeholder') }}"
                    data-character-input
                    required
                >{{ old('body') }}</textarea>
                <small class="support-character-counter" data-character-counter>0 / {{ \KaevCMS\Modules\SupportTickets\Models\SupportTicket::INITIAL_MESSAGE_MAX }}</small>
                @error('body')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
            </div>
        </label>
        <div class="account-form-note">
            <span aria-hidden="true">i</span>
            <p>{{ __('module-support-tickets::messages.limits_note', [
                'subject' => \KaevCMS\Modules\SupportTickets\Models\SupportTicket::SUBJECT_MAX,
                'initial' => \KaevCMS\Modules\SupportTickets\Models\SupportTicket::INITIAL_MESSAGE_MAX,
                'reply' => \KaevCMS\Modules\SupportTickets\Models\SupportTicket::MESSAGE_MAX,
            ]) }}</p>
        </div>
        <div class="account-form-actions">
            <button class="account-button primary" type="submit">{{ __('module-support-tickets::messages.create_ticket') }}</button>
        </div>
    </form>
</section>

<section class="account-section account-surface">
    <div class="account-section-heading">
        <div>
            <h2>{{ __('module-support-tickets::messages.my_tickets') }}</h2>
            <p>{{ __('module-support-tickets::messages.my_tickets_help') }}</p>
        </div>
    </div>

    @if($tickets->isEmpty())
        <div class="account-empty">
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
                <a wire:navigate @class(['account-button secondary', 'disabled' => $tickets->onFirstPage()]) href="{{ $tickets->previousPageUrl() ?? '#' }}">← {{ __('module-support-tickets::messages.previous') }}</a>
                <span>{{ __('module-support-tickets::messages.page_of', ['current' => $tickets->currentPage(), 'last' => $tickets->lastPage()]) }}</span>
                <a wire:navigate @class(['account-button secondary', 'disabled' => ! $tickets->hasMorePages()]) href="{{ $tickets->nextPageUrl() ?? '#' }}">{{ __('module-support-tickets::messages.next') }} →</a>
            </nav>
        @endif
    @endif
</section>
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@push('scripts')
<script src="{{ asset('assets/modules/support-tickets.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
