@extends('admin.layouts.panel')

@section('title', __('Notifications'))
@section('description', __('Choose which actionable events appear in the administrator notification center.'))

@section('content')
@include('admin.settings._system_tabs')

<form class="settings-form" method="POST" action="{{ route('admin.settings.notifications.update') }}">
    @csrf
    @method('PUT')

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Notification types') }}</h2>
                <p>{{ __('Disabled types stop creating new notifications. Existing notifications remain in the list until you clear them.') }}</p>
            </div>
        </div>

        @foreach($notificationTypes as $type)
            <div class="settings-toggle-row">
                <span>
                    <span class="field-label-with-help">
                        <label for="{{ $type['field'] }}"><strong>{{ $type['label'] }}</strong></label>
                        <span class="field-help-tooltip" tabindex="0" aria-label="{{ __('About :item', ['item' => $type['label']]) }}">
                            <span class="field-help-tooltip-icon" aria-hidden="true">?</span>
                            <span class="field-help-tooltip-content" role="tooltip">{{ $type['help'] }}</span>
                        </span>
                    </span>
                </span>
                <label class="switch-control" for="{{ $type['field'] }}">
                    <input name="{{ $type['field'] }}" type="hidden" value="0">
                    <input
                        id="{{ $type['field'] }}"
                        name="{{ $type['field'] }}"
                        type="checkbox"
                        value="1"
                        @checked(old($type['field'], $settings['categories'][$type['category']]))
                    >
                    <span aria-hidden="true"></span>
                </label>
            </div>
        @endforeach
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Notification storage') }}</h2>
                <p>{{ __('Automatic cleanup removes only old notification records and does not change the current system state.') }}</p>
            </div>
        </div>

        <label class="settings-toggle-row" for="notification_auto_cleanup">
            <span>
                <strong>{{ __('Automatically delete old notifications') }}</strong>
                <small>{{ __('The scheduled cleanup runs daily when the server scheduler is configured.') }}</small>
            </span>
            <span class="switch-control">
                <input name="notification_auto_cleanup" type="hidden" value="0">
                <input id="notification_auto_cleanup" name="notification_auto_cleanup" type="checkbox" value="1" @checked(old('notification_auto_cleanup', $settings['auto_cleanup']))>
                <span aria-hidden="true"></span>
            </span>
        </label>

        <div class="form-group">
            <label for="notification_retention_days">{{ __('Keep notifications for') }}</label>
            <select id="notification_retention_days" name="notification_retention_days" required>
                @foreach($retentionOptions as $days)
                    <option value="{{ $days }}" @selected((int) old('notification_retention_days', $settings['retention_days']) === $days)>
                        {{ trans_choice(':count day|:count days', $days, ['count' => $days]) }}
                    </option>
                @endforeach
            </select>
            <small>{{ __('The period is used only when automatic cleanup is enabled.') }}</small>
        </div>
    </section>

    <div class="admin-actions-panel settings-actions settings-actions-narrow">
        <button class="button button-primary" type="submit">{{ __('Save settings') }}</button>
    </div>
</form>
@endsection
