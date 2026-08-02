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

        <x-admin.toggle
            id="registration_enabled"
            name="registration_enabled"
            :label="__('Allow new user registration')"
            :hint="__('Shows the registration button and opens the website account creation page.')"
            :checked="(bool) old('registration_enabled', $settings['enabled'])"
        />

        <x-admin.toggle
            id="email_verification_required"
            name="email_verification_required"
            :label="__('Require email verification')"
            :hint="__('The user can access the account area only after opening the link from the email.')"
            :checked="(bool) old('email_verification_required', $settings['email_verification_required'])"
        />

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
            <x-admin.field for="username_min" name="username_min" :label="__('Minimum length')" :hint="__('Allowed range: 3 to 32.')">
                <input id="username_min" name="username_min" type="number" min="3" max="32" required value="{{ old('username_min', $settings['username_min']) }}" @if($errors->has('username_min')) aria-invalid="true" @endif>
            </x-admin.field>
            <x-admin.field for="username_max" name="username_max" :label="__('Maximum length')" :hint="__('Allowed range: 3 to 64. Must not be less than the minimum.')">
                <input id="username_max" name="username_max" type="number" min="3" max="64" required value="{{ old('username_max', $settings['username_max']) }}" @if($errors->has('username_max')) aria-invalid="true" @endif>
            </x-admin.field>
        </div>

        @foreach([
            'username_allow_hyphen' => [__('Allow hyphen'), __('Users may include the - character in the website username.')],
            'username_allow_underscore' => [__('Allow underscore'), __('Users may include the _ character in the website username.')],
        ] as $field => [$label, $help])
            <x-admin.toggle :id="$field" :name="$field" :label="$label" :hint="$help" :checked="(bool) old($field, $settings[$field])" />
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
            <x-admin.field for="password_min" name="password_min" :label="__('Minimum length')" :hint="__('Allowed range: 8 to 64.')">
                <input id="password_min" name="password_min" type="number" min="8" max="64" required value="{{ old('password_min', $settings['password_min']) }}" @if($errors->has('password_min')) aria-invalid="true" @endif>
            </x-admin.field>
        </div>

        @foreach([
            'password_letters' => [__('Require a letter'), __('At least one Latin letter is required unless mixed case is enabled.')],
            'password_mixed_case' => [__('Require uppercase and lowercase letters'), __('The password must contain both uppercase and lowercase letters.')],
            'password_numbers' => [__('Require a digit'), __('The password must contain at least one digit.')],
            'password_symbols' => [__('Require a symbol'), __('The password must contain at least one symbol.')],
        ] as $field => [$label, $help])
            <x-admin.toggle :id="$field" :name="$field" :label="$label" :hint="$help" :checked="(bool) old($field, $settings[$field])" />
        @endforeach
    </section>

    <div class="admin-actions-panel settings-actions settings-actions-narrow">
        <button class="button button-primary" type="submit">{{ __('Save settings') }}</button>
    </div>
</form>
@endsection
