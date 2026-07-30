@extends('admin.layouts.panel')
@section('title', __('module-support-tickets::messages.settings_title'))
@section('description', __('module-support-tickets::messages.settings_description'))
@section('content')
<div class="content-toolbar">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath]) }}">← {{ __('module-support-tickets::messages.back_to_tickets') }}</a>
</div>

<form class="settings-form" method="POST" action="{{ route('admin.module-pages.support-tickets.settings.update', ['adminPath' => $adminPath]) }}" data-support-editor-permissions>
    @csrf
    @method('PUT')

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('module-support-tickets::messages.editor_permissions') }}</h2>
                <p>{{ __('module-support-tickets::messages.editor_permissions_help') }}</p>
            </div>
        </div>

        @foreach([
            'allow_editor_view' => ['allow_editor_view', 'allow_editor_view_help'],
            'allow_editor_reply' => ['allow_editor_reply', 'allow_editor_reply_help'],
            'allow_editor_internal_notes' => ['allow_editor_internal_notes', 'allow_editor_internal_notes_help'],
        ] as $field => [$label, $help])
            <label class="settings-toggle-row" for="{{ $field }}">
                <span>
                    <strong>{{ __('module-support-tickets::messages.'.$label) }}</strong>
                    <small>{{ __('module-support-tickets::messages.'.$help) }}</small>
                </span>
                <span class="switch-control">
                    <input name="{{ $field }}" type="hidden" value="0">
                    <input
                        id="{{ $field }}"
                        name="{{ $field }}"
                        type="checkbox"
                        value="1"
                        @checked(old($field, $settings[$field]))
                        @if($field === 'allow_editor_view') data-editor-view-toggle @else data-editor-dependent @endif
                    >
                    <span aria-hidden="true"></span>
                </span>
            </label>
        @endforeach
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('module-support-tickets::messages.usage_limits') }}</h2>
                <p>{{ __('module-support-tickets::messages.usage_limits_help') }}</p>
            </div>
        </div>

        <div class="settings-grid two-columns support-limit-settings-grid">
            @foreach([
                'max_tickets_per_day' => [
                    'max_tickets_per_day',
                    'max_tickets_per_day_help',
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MIN_MAX_TICKETS_PER_DAY,
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MAX_MAX_TICKETS_PER_DAY,
                ],
                'max_player_messages_per_day' => [
                    'max_player_messages_per_day',
                    'max_player_messages_per_day_help',
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MIN_MAX_PLAYER_MESSAGES_PER_DAY,
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MAX_MAX_PLAYER_MESSAGES_PER_DAY,
                ],
                'max_messages_per_ticket' => [
                    'max_messages_per_ticket',
                    'max_messages_per_ticket_help',
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MIN_MAX_MESSAGES_PER_TICKET,
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MAX_MAX_MESSAGES_PER_TICKET,
                ],
                'max_revisions_per_message' => [
                    'max_revisions_per_message',
                    'max_revisions_per_message_help',
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MIN_MAX_REVISIONS_PER_MESSAGE,
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MAX_MAX_REVISIONS_PER_MESSAGE,
                ],
                'max_open_tickets_per_user' => [
                    'max_open_tickets_per_user',
                    'max_open_tickets_per_user_help',
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MIN_MAX_OPEN_TICKETS_PER_USER,
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MAX_MAX_OPEN_TICKETS_PER_USER,
                ],
                'subject_max_length' => [
                    'subject_max_length',
                    'subject_max_length_help',
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MIN_SUBJECT_MAX_LENGTH,
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MAX_SUBJECT_MAX_LENGTH,
                ],
                'initial_message_max_length' => [
                    'initial_message_max_length',
                    'initial_message_max_length_help',
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MIN_INITIAL_MESSAGE_MAX_LENGTH,
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MAX_INITIAL_MESSAGE_MAX_LENGTH,
                ],
                'message_max_length' => [
                    'message_max_length',
                    'message_max_length_help',
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MIN_MESSAGE_MAX_LENGTH,
                    \KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings::MAX_MESSAGE_MAX_LENGTH,
                ],
            ] as $field => [$label, $help, $minimum, $maximum])
                <label class="settings-field" for="{{ $field }}">
                    <span>{{ __('module-support-tickets::messages.'.$label) }}</span>
                    <input
                        id="{{ $field }}"
                        name="{{ $field }}"
                        type="number"
                        min="{{ $minimum }}"
                        max="{{ $maximum }}"
                        value="{{ old($field, $settings[$field]) }}"
                        required
                    >
                    <small>{{ __('module-support-tickets::messages.'.$help, ['min' => $minimum, 'max' => $maximum]) }}</small>
                    @error($field)<small class="field-error" role="alert">{{ $message }}</small>@enderror
                </label>
            @endforeach
        </div>
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('module-support-tickets::messages.data_retention') }}</h2>
                <p>{{ __('module-support-tickets::messages.data_retention_help') }}</p>
            </div>
        </div>

        <div class="form-group">
            <label for="retention_months">{{ __('module-support-tickets::messages.retention_period') }}</label>
            <select id="retention_months" name="retention_months" required>
                @foreach([0, 6, 12, 24, 36] as $months)
                    <option value="{{ $months }}" @selected((int) old('retention_months', $settings['retention_months']) === $months)>
                        {{ $months === 0 ? __('module-support-tickets::messages.retention_forever') : trans_choice('module-support-tickets::messages.retention_months', $months, ['count' => $months]) }}
                    </option>
                @endforeach
            </select>
            <small>{{ __('module-support-tickets::messages.retention_period_help') }}</small>
            @error('retention_months')<small class="field-error" role="alert">{{ $message }}</small>@enderror
        </div>

        <label class="settings-toggle-row" for="automatic_cleanup_enabled">
            <span>
                <strong>{{ __('module-support-tickets::messages.automatic_cleanup') }}</strong>
                <small>{{ __('module-support-tickets::messages.automatic_cleanup_help') }}</small>
            </span>
            <span class="switch-control">
                <input name="automatic_cleanup_enabled" type="hidden" value="0">
                <input id="automatic_cleanup_enabled" name="automatic_cleanup_enabled" type="checkbox" value="1" @checked(old('automatic_cleanup_enabled', $settings['automatic_cleanup_enabled']))>
                <span aria-hidden="true"></span>
            </span>
        </label>
    </section>

    <div class="admin-actions-panel settings-actions">
        <button class="button button-primary" type="submit">{{ __('module-support-tickets::messages.save_settings') }}</button>
    </div>
</form>

<section class="form-card settings-narrow-card support-cleanup-card">
    <div class="settings-card-heading">
        <div>
            <h2>{{ __('module-support-tickets::messages.database_cleanup') }}</h2>
            <p>{{ __('module-support-tickets::messages.database_cleanup_help') }}</p>
        </div>
    </div>

    @if(is_array($cleanupPreview) || is_array($cleanupResult))
        @php($report = is_array($cleanupResult) ? $cleanupResult : $cleanupPreview)
        <div class="support-cleanup-report" role="status">
            <div><span>{{ __('module-support-tickets::messages.cleanup_tickets') }}</span><strong>{{ $report['tickets'] }}</strong></div>
            <div><span>{{ __('module-support-tickets::messages.cleanup_messages') }}</span><strong>{{ $report['messages'] }}</strong></div>
            <div><span>{{ __('module-support-tickets::messages.cleanup_revisions') }}</span><strong>{{ $report['revisions'] }}</strong></div>
            <div><span>{{ __('module-support-tickets::messages.cleanup_oldest') }}</span><strong>{{ $report['oldest_closed_at'] ?? '—' }}</strong></div>
            <div><span>{{ __('module-support-tickets::messages.cleanup_newest') }}</span><strong>{{ $report['newest_closed_at'] ?? '—' }}</strong></div>
            <div><span>{{ __('module-support-tickets::messages.cleanup_cutoff') }}</span><strong>{{ $report['cutoff'] ?? '—' }}</strong></div>
        </div>
        <p class="support-cleanup-result-note">
            {{ is_array($cleanupResult) ? __('module-support-tickets::messages.cleanup_result_notice') : __('module-support-tickets::messages.cleanup_preview_notice') }}
        </p>
    @endif

    <div class="support-cleanup-actions">
        <form method="POST" action="{{ route('admin.module-pages.support-tickets.settings.cleanup-preview', ['adminPath' => $adminPath]) }}">
            @csrf
            <button class="button button-secondary" type="submit">{{ __('module-support-tickets::messages.preview_cleanup') }}</button>
        </form>
        <form method="POST" action="{{ route('admin.module-pages.support-tickets.settings.cleanup', ['adminPath' => $adminPath]) }}" data-confirm="{{ __('module-support-tickets::messages.run_cleanup_confirm') }}">
            @csrf
            <button class="button button-danger" type="submit">{{ __('module-support-tickets::messages.run_cleanup') }}</button>
        </form>
    </div>
</section>
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@push('scripts')
<script src="{{ asset('assets/modules/support-tickets.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
