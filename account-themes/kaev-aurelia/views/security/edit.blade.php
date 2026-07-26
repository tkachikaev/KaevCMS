@extends('account-theme::layouts.app')
@section('title', __('Security and password'))
@section('inline-validation-errors', '1')
@section('content')
<section class="account-page-heading account-settings-heading">
    <div>
        <span class="account-eyebrow">{{ __('Your account') }}</span>
        <h1>{{ __('Security and password') }}</h1>
        <p>{{ __('Manage the password used to sign in to your KaevCMS account.') }}</p>
    </div>
    <a wire:navigate class="account-button secondary" href="{{ public_route('account') }}">{{ __('Back to overview') }}</a>
</section>

@include('account-theme::partials.settings-tabs')

<div class="account-security-scope" role="note">
    <span class="account-settings-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="3"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"></path></svg></span>
    <div>
        <strong>{{ __('KaevCMS account security') }}</strong>
        <span>{{ __('Only the password used to sign in to the website changes. Game account passwords remain unchanged.') }}</span>
    </div>
</div>

<section class="account-section account-settings-card account-settings-card-single">
    <div class="account-settings-card-heading">
        <div>
            <span class="account-eyebrow">{{ __('Security') }}</span>
            <h2>{{ __('Change password') }}</h2>
            <p>{{ __('Enter your current password before setting a new one.') }}</p>
        </div>
        <span class="account-settings-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="3"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"></path></svg></span>
    </div>

    <form method="POST" action="{{ public_route('security.password.update') }}" class="account-form-card account-password-form" novalidate>
        @csrf
        @method('PUT')

        <label for="current_password">
            <span>{{ __('Current password') }}</span>
            <div class="account-field-control account-password-control">
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required maxlength="4096" @class(['account-field-invalid' => $errors->has('current_password')]) @error('current_password') aria-describedby="current-password-error" @enderror>
                <button type="button" class="account-password-toggle" data-password-toggle="current_password" data-show-label="{{ __('Show password') }}" data-hide-label="{{ __('Hide password') }}" data-show-text="{{ __('Show') }}" data-hide-text="{{ __('Hide') }}" aria-label="{{ __('Show password') }}" aria-pressed="false">{{ __('Show') }}</button>
                @error('current_password')<small class="account-field-error" id="current-password-error" role="alert">{{ $message }}</small>@enderror
            </div>
        </label>

        <div class="account-form-grid">
            <label for="password">
                <span>{{ __('New password') }}</span>
                <div class="account-field-control account-password-control">
                    <input id="password" name="password" type="password" autocomplete="new-password" required maxlength="4096" @class(['account-field-invalid' => $errors->has('password')]) @error('password') aria-describedby="password-error" @enderror>
                    <button type="button" class="account-password-toggle" data-password-toggle="password" data-show-label="{{ __('Show password') }}" data-hide-label="{{ __('Hide password') }}" data-show-text="{{ __('Show') }}" data-hide-text="{{ __('Hide') }}" aria-label="{{ __('Show password') }}" aria-pressed="false">{{ __('Show') }}</button>
                    @error('password')<small class="account-field-error" id="password-error" role="alert">{{ $message }}</small>@enderror
                </div>
            </label>

            <label for="password_confirmation">
                <span>{{ __('Repeat new password') }}</span>
                <div class="account-field-control account-password-control">
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required maxlength="4096" @class(['account-field-invalid' => $errors->has('password_confirmation')])>
                    <button type="button" class="account-password-toggle" data-password-toggle="password_confirmation" data-show-label="{{ __('Show password') }}" data-hide-label="{{ __('Hide password') }}" data-show-text="{{ __('Show') }}" data-hide-text="{{ __('Hide') }}" aria-label="{{ __('Show password') }}" aria-pressed="false">{{ __('Show') }}</button>
                </div>
            </label>
        </div>

        <div class="account-form-note">
            <strong>{{ __('Password requirements') }}</strong>
            <span>{{ __('At least 8 characters, including a letter and a digit.') }}</span>
        </div>

        <div class="account-form-actions">
            <button type="submit" class="account-button primary">{{ __('Change password') }}</button>
        </div>
    </form>
</section>
@endsection
