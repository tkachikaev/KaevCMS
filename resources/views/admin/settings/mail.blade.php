@extends('admin.layouts.panel')
@section('title', __('Mail'))
@section('description', __('SMTP for email verification and password recovery.'))
@section('content')
@include('admin.settings._mail_tabs')

<div class="mail-settings-status">
    @if($settings['password_saved'] && ! $settings['password_valid'])
        <span class="status-badge status-badge-danger">{{ __('Password unavailable') }}</span><span>{{ __('The saved SMTP password cannot be decrypted with the current APP_KEY. Enter it again and save the settings.') }}</span>
    @elseif($settings['ready'])
        <span class="status-badge status-badge-success">{{ __('Mail verified') }}</span><span>{{ __('Last successful test: :date', ['date'=>\Illuminate\Support\Carbon::parse($settings['tested_at'])->format('d.m.Y H:i')]) }}</span>
    @elseif($settings['configured'])
        <span class="status-badge status-badge-warning">{{ __('Verification required') }}</span><span>{{ __('Settings are saved, but no test email has been sent yet.') }}</span>
    @else
        <span class="status-badge status-badge-muted">{{ __('Not configured') }}</span><span>{{ __('Fill in the SMTP settings and save the form.') }}</span>
    @endif
</div>

<div class="settings-grid mail-settings-grid">
    <form class="settings-form" method="POST" action="{{ route('admin.settings.mail.update') }}">
        @csrf
                    @method('PUT')
        <section class="form-card">
            <h2>{{ __('SMTP connection') }}</h2>
            <div class="form-row form-row-2-1">
                <div class="form-group"><label for="smtp_host">{{ __('SMTP server') }}</label><input id="smtp_host" name="smtp_host" type="text" maxlength="255" required value="{{ old('smtp_host',$settings['host']) }}" placeholder="smtp.example.com" autocomplete="off"></div>
                <div class="form-group"><label for="smtp_port">{{ __('Port') }}</label><input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" required value="{{ old('smtp_port',$settings['port']) }}" placeholder="587"></div>
            </div>
            <div class="form-group"><label for="encryption">{{ __('Secure connection') }}</label><select id="encryption" name="encryption" required><option value="tls" @selected(old('encryption',$settings['encryption'])==='tls')>{{ __('STARTTLS / TLS — usually port 587') }}</option><option value="ssl" @selected(old('encryption',$settings['encryption'])==='ssl')>{{ __('SSL / SMTPS — usually port 465') }}</option><option value="none" @selected(old('encryption',$settings['encryption'])==='none')>{{ __('No forced encryption') }}</option></select></div>
            <div class="form-group"><label for="smtp_username">{{ __('SMTP username') }}</label><input id="smtp_username" name="smtp_username" type="text" maxlength="255" value="{{ old('smtp_username',$settings['username']) }}" placeholder="no-reply@example.com" autocomplete="off"><small>{{ __('May be left empty if the SMTP server does not require authentication.') }}</small></div>
            <div class="form-group"><label for="smtp_password">{{ __('SMTP password') }}</label><input id="smtp_password" name="smtp_password" type="password" maxlength="1024" value="" placeholder="{{ $settings['password_saved'] && $settings['password_valid'] ? __('Password already saved') : __('Enter password') }}" autocomplete="new-password"><small>@if($settings['password_saved'] && $settings['password_valid']){{ __('The current password is encrypted. Leave this field empty to keep it.') }}@elseif($settings['password_saved']){{ __('The saved password can no longer be decrypted. Enter it again.') }}@else{{ __('The password is encrypted using APP_KEY.') }}@endif</small></div>
        </section>
        <section class="form-card">
            <h2>{{ __('Sender') }}</h2>
            <div class="form-group"><label for="from_address">{{ __('Sender email') }}</label><input id="from_address" name="from_address" type="email" maxlength="255" required value="{{ old('from_address',$settings['from_address']) }}" placeholder="no-reply@example.com"></div>
            <div class="form-group"><label for="from_name">{{ __('Sender name') }}</label><input id="from_name" name="from_name" type="text" maxlength="100" required value="{{ old('from_name',$settings['from_name']) }}" placeholder="{{ site_name() }}"></div>
            <div class="form-group"><label for="notification_email">{{ __('System notification email') }}</label><input id="notification_email" name="notification_email" type="email" maxlength="255" value="{{ old('notification_email',$settings['admin_email']) }}" placeholder="admin@example.com"><small>{{ __('Reserved for future administrator notifications. This is not the SMTP login.') }}</small></div>
        </section>
        <div class="admin-actions-panel settings-actions settings-actions-inside"><button class="button button-primary" type="submit">{{ __('Save mail settings') }}</button></div>
    </form>
    <aside>
        <form class="settings-form" method="POST" action="{{ route('admin.settings.mail.test') }}">@csrf<section class="form-card mail-test-card"><h2>{{ __('Test delivery') }}</h2><p>{{ __('The test uses saved settings. Run it again after any SMTP change.') }}</p><div class="form-group"><label for="test_email">{{ __('Test email address') }}</label><input id="test_email" name="test_email" type="email" maxlength="255" required value="{{ old('test_email',$settings['admin_email']) }}" placeholder="admin@example.com"></div><button class="button button-secondary" type="submit" @disabled(!$settings['configured'])>{{ __('Send test email') }}</button></section></form>
        <section class="form-card mail-help-card"><h2>{{ __('What you need') }}</h2><ul class="settings-help-list"><li>{{ __('SMTP server and port from your mail provider.') }}</li><li>{{ __('Username and password or an application password.') }}</li><li>{{ __('SPF, DKIM and DMARC records for the sender domain.') }}</li><li>{{ __('A correct APP_URL for verification and password reset links.') }}</li></ul><p class="muted-admin">{{ __('Secrets are never rendered back into HTML and must not be written to logs.') }}</p></section>
    </aside>
</div>
@endsection

