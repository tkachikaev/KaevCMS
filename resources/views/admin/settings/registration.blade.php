@extends('admin.layouts.panel')

@section('title', __('Registration'))
@section('description', __('Registration for public website users.'))

@section('content')
@include('admin.settings._system_tabs')

<form class="settings-form" method="POST" action="{{ route('admin.settings.registration.update') }}">
    @csrf
    @method('PUT')

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('User registration') }}</h2>
                <p>{{ __('These accounts belong to the website only. Lineage II game accounts are created separately.') }}</p>
            </div>
        </div>

        <label class="settings-toggle-row" for="registration_enabled">
            <span>
                <strong>{{ __('Allow new user registration') }}</strong>
                <small>{{ __('Shows the registration button and opens the website account creation page.') }}</small>
            </span>
            <span class="switch-control">
                <input name="registration_enabled" type="hidden" value="0">
                <input id="registration_enabled" name="registration_enabled" type="checkbox" value="1" @checked(old('registration_enabled', $settings['enabled']))>
                <span aria-hidden="true"></span>
            </span>
        </label>

        <label class="settings-toggle-row" for="email_verification_required">
            <span>
                <strong>{{ __('Require email verification') }}</strong>
                <small>{{ __('The user can access the account area only after opening the link from the email.') }}</small>
            </span>
            <span class="switch-control">
                <input name="email_verification_required" type="hidden" value="0">
                <input id="email_verification_required" name="email_verification_required" type="checkbox" value="1" @checked(old('email_verification_required', $settings['email_verification_required']))>
                <span aria-hidden="true"></span>
            </span>
        </label>

        @if($mailReady)
            <div class="notice notice-success settings-inline-notice">
                <p><strong>{{ __('Mail is verified.') }}</strong> {{ __('Email verification may be enabled.') }}</p>
            </div>
        @else
            <div class="notice notice-warning settings-inline-notice">
                <p><strong>{{ __('Mail is not verified yet.') }}</strong> {{ __('Save SMTP settings and send a test email before enabling verified registration.') }}</p>
                <p><a wire:navigate href="{{ route('admin.settings.mail') }}">{{ __('Open mail settings') }} →</a></p>
            </div>
        @endif
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Website username policy') }}</h2>
                <p>{{ __('Latin letters and digits are always allowed. Configure the length and optional separators.') }}</p>
            </div>
        </div>

        <div class="security-field-grid">
            <div class="form-group">
                <label for="username_min">{{ __('Minimum length') }}</label>
                <input id="username_min" name="username_min" type="number" min="3" max="32" required value="{{ old('username_min', $settings['username_min']) }}">
                <small>{{ __('Allowed range: 3 to 32.') }}</small>
            </div>
            <div class="form-group">
                <label for="username_max">{{ __('Maximum length') }}</label>
                <input id="username_max" name="username_max" type="number" min="3" max="64" required value="{{ old('username_max', $settings['username_max']) }}">
                <small>{{ __('Allowed range: 3 to 64. Must not be less than the minimum.') }}</small>
            </div>
        </div>

        @foreach([
            'username_allow_hyphen' => [__('Allow hyphen'), __('Users may include the - character in the website username.')],
            'username_allow_underscore' => [__('Allow underscore'), __('Users may include the _ character in the website username.')],
        ] as $field => [$label, $help])
            <label class="settings-toggle-row" for="{{ $field }}">
                <span><strong>{{ $label }}</strong><small>{{ $help }}</small></span>
                <span class="switch-control">
                    <input name="{{ $field }}" type="hidden" value="0">
                    <input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked(old($field, $settings[$field]))>
                    <span aria-hidden="true"></span>
                </span>
            </label>
        @endforeach
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Website password policy') }}</h2>
                <p>{{ __('The policy applies to registration, password reset and password changes in the player account.') }}</p>
            </div>
        </div>

        <div class="security-field-grid registration-policy-single-field">
            <div class="form-group">
                <label for="password_min">{{ __('Minimum length') }}</label>
                <input id="password_min" name="password_min" type="number" min="8" max="64" required value="{{ old('password_min', $settings['password_min']) }}">
                <small>{{ __('Allowed range: 8 to 64.') }}</small>
            </div>
        </div>

        @foreach([
            'password_letters' => [__('Require a letter'), __('At least one Latin letter is required unless mixed case is enabled.')],
            'password_mixed_case' => [__('Require uppercase and lowercase letters'), __('The password must contain both uppercase and lowercase letters.')],
            'password_numbers' => [__('Require a digit'), __('The password must contain at least one digit.')],
            'password_symbols' => [__('Require a symbol'), __('The password must contain at least one symbol.')],
        ] as $field => [$label, $help])
            <label class="settings-toggle-row" for="{{ $field }}">
                <span><strong>{{ $label }}</strong><small>{{ $help }}</small></span>
                <span class="switch-control">
                    <input name="{{ $field }}" type="hidden" value="0">
                    <input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked(old($field, $settings[$field]))>
                    <span aria-hidden="true"></span>
                </span>
            </label>
        @endforeach
    </section>

    <div class="admin-actions-panel settings-actions settings-actions-narrow">
        <button class="button button-primary" type="submit">{{ __('Save settings') }}</button>
    </div>
</form>
@endsection
