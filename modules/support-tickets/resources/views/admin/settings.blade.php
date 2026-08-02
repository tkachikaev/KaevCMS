@extends('admin.layouts.panel')
@section('title', __('module-support-tickets::messages.settings_title'))
@section('description', __('module-support-tickets::messages.settings_description'))
@section('content')
@php($activeTab = request()->query('tab') === 'cleanup' ? 'cleanup' : 'general')
<div class="content-toolbar">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath]) }}">← {{ __('module-support-tickets::messages.back_to_tickets') }}</a>
</div>

<x-admin.tabs :label="__('module-support-tickets::messages.settings_sections')" class="settings-section-tabs support-settings-tabs" data-testid="support-settings-tabs">
    <x-admin.tab
        wire:navigate
        :href="route('admin.module-pages.support-tickets.settings', ['adminPath' => $adminPath])"
        :active="$activeTab === 'general'"
        class="settings-section-tab"
    >
        {{ __('module-support-tickets::messages.settings_tab_general') }}
    </x-admin.tab>
    <x-admin.tab
        wire:navigate
        :href="route('admin.module-pages.support-tickets.settings', ['adminPath' => $adminPath, 'tab' => 'cleanup'])"
        :active="$activeTab === 'cleanup'"
        class="settings-section-tab"
    >
        {{ __('module-support-tickets::messages.settings_tab_cleanup') }}
    </x-admin.tab>
</x-admin.tabs>

@if($activeTab === 'general')
    <form class="settings-form" method="POST" action="{{ route('admin.module-pages.support-tickets.settings.update', ['adminPath' => $adminPath]) }}" data-support-editor-permissions>
        @csrf
        @method('PUT')

        <x-admin.card narrow>
            <x-admin.card-heading
                :title="__('module-support-tickets::messages.editor_permissions')"
                :description="__('module-support-tickets::messages.editor_permissions_help')"
            />

            @foreach([
                'allow_editor_view' => ['allow_editor_view', 'allow_editor_view_help'],
                'allow_editor_reply' => ['allow_editor_reply', 'allow_editor_reply_help'],
                'allow_editor_internal_notes' => ['allow_editor_internal_notes', 'allow_editor_internal_notes_help'],
            ] as $field => [$label, $help])
                <x-admin.toggle
                    :id="$field"
                    :name="$field"
                    :label="__('module-support-tickets::messages.'.$label)"
                    :hint="__('module-support-tickets::messages.'.$help)"
                    :checked="(bool) old($field, $settings[$field])"
                    :input-attributes="$field === 'allow_editor_view'
                        ? ['data-editor-view-toggle' => '']
                        : ['data-editor-dependent' => '']"
                />
            @endforeach
        </x-admin.card>

        <x-admin.card narrow>
            <x-admin.card-heading
                :title="__('module-support-tickets::messages.usage_limits')"
                :description="__('module-support-tickets::messages.usage_limits_help')"
            />

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
                    <x-admin.field
                        :for="$field"
                        :name="$field"
                        :label="__('module-support-tickets::messages.'.$label)"
                        :hint="__('module-support-tickets::messages.'.$help, ['min' => $minimum, 'max' => $maximum])"
                    >
                        <input
                            id="{{ $field }}"
                            name="{{ $field }}"
                            type="number"
                            min="{{ $minimum }}"
                            max="{{ $maximum }}"
                            value="{{ old($field, $settings[$field]) }}"
                            required
                            @if($errors->has($field)) aria-invalid="true" @endif
                        >
                    </x-admin.field>
                @endforeach
            </div>
        </x-admin.card>

        <x-admin.card narrow>
            <x-admin.card-heading
                :title="__('module-support-tickets::messages.data_retention')"
                :description="__('module-support-tickets::messages.data_retention_help')"
            />

            <x-admin.field
                for="retention_months"
                name="retention_months"
                :label="__('module-support-tickets::messages.retention_period')"
                :hint="__('module-support-tickets::messages.retention_period_help')"
            >
                <select id="retention_months" name="retention_months" required @if($errors->has('retention_months')) aria-invalid="true" @endif>
                    @foreach([0, 6, 12, 24, 36] as $months)
                        <option value="{{ $months }}" @selected((int) old('retention_months', $settings['retention_months']) === $months)>
                            {{ $months === 0 ? __('module-support-tickets::messages.retention_forever') : trans_choice('module-support-tickets::messages.retention_months', $months, ['count' => $months]) }}
                        </option>
                    @endforeach
                </select>
            </x-admin.field>

            <x-admin.toggle
                id="automatic_cleanup_enabled"
                name="automatic_cleanup_enabled"
                :label="__('module-support-tickets::messages.automatic_cleanup')"
                :hint="__('module-support-tickets::messages.automatic_cleanup_help')"
                :checked="(bool) old('automatic_cleanup_enabled', $settings['automatic_cleanup_enabled'])"
            />
        </x-admin.card>

        <div class="admin-actions-panel settings-actions support-settings-actions">
            <x-admin.button type="submit" variant="primary">{{ __('module-support-tickets::messages.save_settings') }}</x-admin.button>
        </div>
    </form>
@else
    <x-admin.card narrow class="support-cleanup-card" data-testid="support-cleanup-panel">
        <x-admin.card-heading
            :title="__('module-support-tickets::messages.database_cleanup')"
            :description="__('module-support-tickets::messages.database_cleanup_help')"
        />

        <div class="support-cleanup-policy">
            <div>
                <span>{{ __('module-support-tickets::messages.retention_period') }}</span>
                <strong>
                    {{ (int) $settings['retention_months'] === 0
                        ? __('module-support-tickets::messages.retention_forever')
                        : trans_choice('module-support-tickets::messages.retention_months', (int) $settings['retention_months'], ['count' => (int) $settings['retention_months']]) }}
                </strong>
            </div>
            <div>
                <span>{{ __('module-support-tickets::messages.automatic_cleanup') }}</span>
                <strong>{{ $settings['automatic_cleanup_enabled'] ? __('module-support-tickets::messages.enabled') : __('module-support-tickets::messages.disabled') }}</strong>
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
                <x-admin.button type="submit">{{ __('module-support-tickets::messages.preview_cleanup') }}</x-admin.button>
            </form>
            <form method="POST" action="{{ route('admin.module-pages.support-tickets.settings.cleanup', ['adminPath' => $adminPath]) }}" data-confirm="{{ __('module-support-tickets::messages.run_cleanup_confirm') }}">
                @csrf
                <x-admin.button type="submit" variant="danger">{{ __('module-support-tickets::messages.run_cleanup') }}</x-admin.button>
            </form>
        </div>
    </x-admin.card>
@endif
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@push('scripts')
<script src="{{ asset('assets/modules/support-tickets.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
