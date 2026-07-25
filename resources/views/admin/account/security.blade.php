@extends('admin.layouts.panel')
@section('title', __('Account security'))
@section('description', __('Password-independent protection for the current administrator account.'))
@section('content')
<div class="admin-overview account-security-toolbar">
    <div>
        <span>{{ __('Current administrator') }}</span>
        <strong>{{ $administrator->name }}</strong>
        <small>{{ $administrator->email }}</small>
    </div>
    <span @class(['status-badge', 'status-badge-success' => $administrator->twoFactorEnabled(), 'status-badge-muted' => ! $administrator->twoFactorEnabled()])>
        {{ $administrator->twoFactorEnabled() ? __('2FA enabled') : __('2FA disabled') }}
    </span>
</div>

@if (is_array($recoveryCodes) && $recoveryCodes !== [])
    <section class="form-card recovery-codes-card" data-recovery-codes>
        <textarea hidden data-recovery-code-source>{{ implode("\n", $recoveryCodes) }}</textarea>
        <div class="settings-card-heading">
            <h2>{{ __('Save your recovery codes') }}</h2>
            <p>{{ __('Each code can be used once. They will not be shown again after leaving this page.') }}</p>
        </div>
        <div class="recovery-code-grid">
            @foreach ($recoveryCodes as $recoveryCode)
                <code>{{ $recoveryCode }}</code>
            @endforeach
        </div>
        <div class="recovery-code-actions">
            <button class="button button-secondary" type="button" data-copy-recovery-codes>{{ __('Copy codes') }}</button>
            <button class="button button-secondary" type="button" data-download-recovery-codes data-filename="kaevcms-recovery-codes.txt">{{ __('Download codes') }}</button>
        </div>
    </section>
@endif

@if (! $administrator->twoFactorEnabled())
    @if (is_string($setupSecret) && is_string($provisioningUri))
        <div class="account-security-grid">
            <section class="form-card two-factor-setup-card">
                <div class="settings-card-heading">
                    <h2>{{ __('Connect an authenticator app') }}</h2>
                    <p>{{ __('Scan the QR code with an authenticator app, then enter the generated six-digit code.') }}</p>
                </div>
                <div class="two-factor-qr" data-two-factor-qr data-uri="{{ $provisioningUri }}" aria-label="{{ __('Two-factor authentication QR code') }}"></div>
                <div class="two-factor-manual-key">
                    <span>{{ __('Manual setup key') }}</span>
                    <code>{{ $setupSecret }}</code>
                </div>
            </section>

            <form class="administrator-form" method="POST" action="{{ route('admin.account.two-factor.confirm') }}">
                @csrf
                <section class="form-card administrator-form-card">
                    <h2>{{ __('Confirm setup') }}</h2>
                    <div class="form-group">
                        <label for="code">{{ __('Six-digit code') }}</label>
                        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
                        <small>{{ __('The setup is not enabled until this code is verified.') }}</small>
                    </div>
                    <button class="button button-primary administrator-password-button" type="submit">{{ __('Confirm and enable 2FA') }}</button>
                </section>
            </form>
        </div>
    @else
        <section class="form-card settings-narrow-card">
            <div class="settings-card-heading">
                <h2>{{ __('Two-factor authentication') }}</h2>
                <p>{{ __('After the password, sign-in will require a code from an authenticator app.') }}</p>
            </div>
            <div class="two-factor-feature-list">
                <div><strong>{{ __('Authenticator app') }}</strong><span>{{ __('Compatible with standard TOTP applications.') }}</span></div>
                <div><strong>{{ __('Recovery codes') }}</strong><span>{{ __('Eight one-time codes are created for emergency access.') }}</span></div>
                <div><strong>{{ __('Account only') }}</strong><span>{{ __('This setting affects only your administrator account.') }}</span></div>
            </div>
            <form method="POST" action="{{ route('admin.account.two-factor.setup') }}" class="two-factor-enable-form">
                @csrf
                <div class="form-group">
                    <label for="current_password">{{ __('Current password') }}</label>
                    <input id="current_password" name="current_password" type="password" maxlength="4096" autocomplete="current-password" required>
                    <small>{{ __('Other active administrator sessions will be revoked.') }}</small>
                </div>
                <button class="button button-primary" type="submit">{{ __('Enable 2FA') }}</button>
            </form>
        </section>
    @endif
@else
    <div class="account-security-grid">
        <section class="form-card administrator-form-card">
            <h2>{{ __('Two-factor authentication') }}</h2>
            <dl class="account-security-status-list">
                <div><dt>{{ __('Status') }}</dt><dd><span class="status-badge status-badge-success">{{ __('Enabled') }}</span></dd></div>
                <div><dt>{{ __('Connected') }}</dt><dd>{{ $administrator->two_factor_confirmed_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
                <div><dt>{{ __('Recovery codes remaining') }}</dt><dd>{{ $administrator->recoveryCodesRemaining() ?? __('Unavailable') }}</dd></div>
            </dl>
        </section>

        <div class="administrator-side-column">
            <form class="administrator-form" method="POST" action="{{ route('admin.account.two-factor.recovery-codes') }}">
                @csrf
                <section class="form-card administrator-form-card">
                    <h2>{{ __('Generate new recovery codes') }}</h2>
                    <p class="two-factor-card-copy">{{ __('Existing recovery codes will stop working immediately.') }}</p>
                    <div class="form-group"><label for="recovery_current_password">{{ __('Current password') }}</label><input id="recovery_current_password" name="current_password" type="password" maxlength="4096" autocomplete="current-password" required></div>
                    <div class="form-group"><label for="recovery_code">{{ __('Current authentication code') }}</label><input id="recovery_code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></div>
                    <button class="button button-secondary administrator-password-button" type="submit">{{ __('Generate new codes') }}</button>
                </section>
            </form>

            <form class="administrator-form" method="POST" action="{{ route('admin.account.two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <section class="form-card administrator-form-card two-factor-danger-card">
                    <h2>{{ __('Disable two-factor authentication') }}</h2>
                    <p class="two-factor-card-copy">{{ __('Other active administrator sessions will be revoked.') }}</p>
                    <div class="form-group"><label for="disable_current_password">{{ __('Current password') }}</label><input id="disable_current_password" name="current_password" type="password" maxlength="4096" autocomplete="current-password" required></div>
                    <div class="form-group"><label for="disable_code">{{ __('Authentication or recovery code') }}</label><input id="disable_code" name="code" type="text" autocomplete="one-time-code" maxlength="64" required></div>
                    <button class="button button-danger administrator-password-button" type="submit">{{ __('Disable 2FA') }}</button>
                </section>
            </form>
        </div>
    </div>
@endif
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/qrcode.bundle.js') }}?v={{ cms_version() }}" data-navigate-once defer></script>
<script src="{{ asset('assets/admin/js/two-factor.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
