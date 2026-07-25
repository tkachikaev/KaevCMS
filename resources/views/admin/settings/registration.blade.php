@extends('admin.layouts.panel')
@section('title', __('Registration'))
@section('description', __('Registration for public website users.'))
@section('content')
@include('admin.settings._system_tabs')

<form class="settings-form" method="POST" action="{{ route('admin.settings.registration.update') }}">
    @csrf
                    @method('PUT')
    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading"><div><h2>{{ __('User registration') }}</h2><p>{{ __('These accounts belong to the website only. Lineage II game accounts are created separately.') }}</p></div></div>
        <div class="registration-rules-card" aria-label="{{ __('User registration rules') }}">
            <div><h3>{{ __('Username requirements') }}</h3><ul><li>{{ __('3 to 32 characters;') }}</li><li>{{ __('Latin letters;') }}</li><li>{{ __('digits;') }}</li><li>{{ __('hyphen and underscore.') }}</li></ul></div>
            <div><h3>{{ __('Password requirements') }}</h3><ul><li>{{ __('at least 8 characters;') }}</li><li>{{ __('at least one letter;') }}</li><li>{{ __('at least one digit.') }}</li></ul></div>
        </div>
        <label class="settings-toggle-row" for="registration_enabled"><span><strong>{{ __('Allow new user registration') }}</strong><small>{{ __('Shows the registration button and opens the website account creation page.') }}</small></span><span class="switch-control"><input name="registration_enabled" type="hidden" value="0"><input id="registration_enabled" name="registration_enabled" type="checkbox" value="1" @checked(old('registration_enabled',$settings['enabled']))><span aria-hidden="true"></span></span></label>
        <label class="settings-toggle-row" for="email_verification_required"><span><strong>{{ __('Require email verification') }}</strong><small>{{ __('The user can access the account area only after opening the link from the email.') }}</small></span><span class="switch-control"><input name="email_verification_required" type="hidden" value="0"><input id="email_verification_required" name="email_verification_required" type="checkbox" value="1" @checked(old('email_verification_required',$settings['email_verification_required']))><span aria-hidden="true"></span></span></label>
        @if($mailReady)
            <div class="notice notice-success settings-inline-notice"><p><strong>{{ __('Mail is verified.') }}</strong> {{ __('Email verification may be enabled.') }}</p></div>
        @else
            <div class="notice notice-warning settings-inline-notice"><p><strong>{{ __('Mail is not verified yet.') }}</strong> {{ __('Save SMTP settings and send a test email before enabling verified registration.') }}</p><p><a wire:navigate href="{{ route('admin.settings.mail') }}">{{ __('Open mail settings') }} →</a></p></div>
        @endif
    </section>
    <div class="admin-actions-panel settings-actions settings-actions-narrow"><button class="button button-primary" type="submit">{{ __('Save settings') }}</button></div>
</form>
@endsection
