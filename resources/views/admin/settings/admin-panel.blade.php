@extends('admin.layouts.panel')

@section('title', __('Administrator panel'))
@section('description', __('Administrator address and server status refresh settings.'))

@section('content')
@php($canChangeAdminPath = auth('admin')->user()->hasPermission(\App\Auth\AdminPermission::AdminPathManage))
@include('admin.settings._system_tabs')

@if(! $canChangeAdminPath)
    <div class="notice notice-info admin-critical-setting-notice"><p>{{ __('Only an owner can change the administrator panel address.') }}</p></div>
@endif

<section class="form-card admin-path-settings-card">
    <form
        method="POST"
        action="{{ route('admin.settings.admin-panel.admin-path.update') }}"
        data-admin-path-form
        data-current-admin-path="{{ $adminPath }}"
    >
        @csrf
        @method('PUT')
        <fieldset class="admin-inline-permission-fieldset" @disabled(! $canChangeAdminPath)>

        <div class="system-monitor-settings-heading">
            <div>
                <div class="field-label-with-help">
                    <h2>{{ __('Administrator panel address') }}</h2>
                    <span class="field-help-tooltip admin-path-help" tabindex="0" aria-label="{{ __('Administrator path recovery') }}">
                        <span class="field-help-tooltip-icon" aria-hidden="true">i</span>
                        <span class="field-help-tooltip-content" role="tooltip">
                            <strong>{{ __('Administrator path recovery') }}</strong>
                            <span>{{ __('If you forget the changed address, use the console commands on the server.') }}</span>
                            <code>php artisan kaevcms:admin-path</code>
                            <code>php artisan kaevcms:admin-path --reset</code>
                            <code>php artisan kaevcms:admin-path test01</code>
                        </span>
                    </span>
                </div>
                <p>{{ __('Change only the suffix after the fixed admin- prefix.') }}</p>
            </div>
            <span class="admin-path-current">{{ __('Current address') }}: <code>{{ $adminPath }}</code></span>
        </div>

        <div class="admin-path-settings-controls">
            <div class="form-group">
                <label for="admin_path_suffix">{{ __('Administrator path suffix') }}</label>
                <div class="admin-path-input">
                    <span aria-hidden="true">admin-</span>
                    <input
                        id="admin_path_suffix"
                        name="admin_path_suffix"
                        type="text"
                        value="{{ old('admin_path_suffix', $adminPathSuffix) }}"
                        maxlength="40"
                        pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                        autocomplete="off"
                        spellcheck="false"
                        data-admin-path-suffix
                    >
                </div>
                <small>{{ __('Use 3 to 40 lowercase Latin letters, numbers, and single hyphens. Leave the suffix empty to use /admin.') }}</small>
                @error('admin_path_suffix')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <button class="button button-primary" type="submit">{{ __('Change address') }}</button>
        </div>
        </fieldset>
    </form>
</section>

@if($canChangeAdminPath)
<dialog class="confirm-dialog admin-path-confirm-dialog" data-admin-path-dialog aria-labelledby="admin-path-dialog-title">
    <div class="confirm-dialog-card">
        <div class="confirm-dialog-copy">
            <span class="confirm-dialog-mark" aria-hidden="true">!</span>
            <div>
                <h2 id="admin-path-dialog-title">{{ __('Change administrator panel address?') }}</h2>
                <p>{{ __('Current address') }}: <strong data-admin-path-current>{{ $adminPath }}</strong></p>
                <p>{{ __('New address') }}: <strong data-admin-path-new></strong></p>
                <p class="admin-path-danger">{{ __('After the change, the old administrator address will stop working. Save the new address so that you do not lose access to the site.') }}</p>
                <p class="admin-path-recovery-note">{{ __('If the address is lost, reset it with:') }} <code>php artisan kaevcms:admin-path --reset</code></p>
            </div>
        </div>
        <div class="confirm-dialog-actions">
            <button class="button button-secondary" type="button" data-admin-path-cancel>{{ __('Cancel') }}</button>
            <button class="button button-danger" type="button" data-admin-path-confirm>{{ __('Change address') }}</button>
        </div>
    </div>
</dialog>
@endif

<section class="form-card system-monitor-settings-card">
    <form method="POST" action="{{ route('admin.settings.admin-panel.monitoring.update') }}">
        @csrf
        @method('PUT')

        <div class="system-monitor-settings-heading">
            <div>
                <h2>{{ __('Server monitoring') }}</h2>
                <p>{{ __('CMS refreshes stale server status when the public home page or administrator dashboard is opened.') }}</p>
            </div>
        </div>

        <div class="system-monitor-settings-controls">
            <div class="form-group">
                <div class="field-label-with-help">
                    <label for="refresh_interval_seconds">{{ __('Server status refresh interval') }}</label>
                    <span class="field-help-tooltip" tabindex="0" aria-label="{{ __('About the server status refresh interval') }}">
                        <span class="field-help-tooltip-icon" aria-hidden="true">?</span>
                        <span class="field-help-tooltip-content" role="tooltip">{{ __('How often CMS may repeat LoginServer and GameServer availability checks and update the online player count. Between checks, the site uses the saved result.') }}</span>
                    </span>
                </div>
                <select id="refresh_interval_seconds" name="refresh_interval_seconds" required>
                    @foreach($monitorRefreshOptions as $seconds)
                        <option
                            value="{{ $seconds }}"
                            @selected((int) old('refresh_interval_seconds', $monitorSettings['refresh_interval_seconds']) === $seconds)
                        >
                            {{ match ($seconds) {
                                30 => __('30 seconds'),
                                60 => __('1 minute'),
                                120 => __('2 minutes'),
                                300 => __('5 minutes'),
                                default => __(':count seconds', ['count' => $seconds]),
                            } }}
                        </option>
                    @endforeach
                </select>
                <small>{{ __('Minimum: 30 seconds. Default: 1 minute.') }}</small>
                @error('refresh_interval_seconds')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <button class="button button-primary" type="submit">{{ __('Save monitoring settings') }}</button>
        </div>
    </form>
</section>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/admin-panel.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
