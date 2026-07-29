@extends('theme::layouts.app')

@section('title', __('Register').' — '.site_name())

@section('content')
<section class="auth-page">
    <div class="panel auth-card">
        <p class="eyebrow">{{ __('NEW SITE ACCOUNT') }}</p>
        <h1>{{ __('Register') }}</h1>
        <p class="muted">{{ __('A site user will be created. The Lineage II game account is registered separately.') }}</p>

        <form method="POST" action="{{ public_route('register.store') }}">
            @csrf

            <label for="name">{{ __('Login') }}
                <input id="name" name="name" type="text" minlength="{{ $registrationPolicy['username_min'] }}" maxlength="{{ $registrationPolicy['username_max'] }}" required autofocus autocomplete="username" value="{{ old('name') }}" pattern="{{ $usernamePattern }}">
                <small>{{ implode(' ', $usernameRequirements) }}</small>
            </label>

            <label for="email">Email
                <input id="email" name="email" type="email" maxlength="255" required autocomplete="email" value="{{ old('email') }}">
            </label>

            <label for="password">{{ __('Password') }}
                <input id="password" name="password" type="password" minlength="{{ $registrationPolicy['password_min'] }}" required autocomplete="new-password">
                <small>{{ implode(' ', $passwordRequirements) }}</small>
            </label>

            <label for="password_confirmation">{{ __('Confirm password') }}
                <input id="password_confirmation" name="password_confirmation" type="password" minlength="{{ $registrationPolicy['password_min'] }}" required autocomplete="new-password">
            </label>

            @if ($emailVerificationRequired)
                <p class="auth-info">{{ __('A verification link will be sent to the specified email after registration.') }}</p>
            @endif

            <button class="button button-gold" type="submit">{{ __('Create account') }}</button>
        </form>

        <div class="auth-links auth-links-center">
            <a href="{{ public_route('login') }}">{{ __('Already registered? Sign in') }}</a>
        </div>
    </div>
</section>
@endsection
