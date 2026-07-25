@extends('theme::layouts.app')

@section('title', __('Sign in').' — '.site_name())

@section('content')
<section class="auth-page">
    <div class="panel auth-card">
        <p class="eyebrow">{{ __('Personal account eyebrow') }}</p>
        <h1>{{ __('Sign in') }}</h1>
        <p class="muted">{{ __('Use the login or email of your site account.') }}</p>

        <form method="POST" action="{{ public_route('login.store') }}">
            @csrf

            <label for="login">{{ __('Username or email') }}
                <input id="login" name="login" type="text" maxlength="255" required autofocus autocomplete="username" value="{{ old('login') }}">
            </label>

            <label for="password">{{ __('Password') }}
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </label>

            <label class="auth-checkbox" for="remember">
                <input id="remember" name="remember" type="checkbox" value="1" @checked(old('remember'))>
                <span>{{ __('Remember me') }}</span>
            </label>

            <button class="button button-gold" type="submit">{{ __('Log in') }}</button>
        </form>

        <div class="auth-links">
            <a wire:navigate.hover href="{{ public_route('password.request') }}">{{ __('Forgot your password?') }}</a>
            @if (registration_available())
                <a wire:navigate.hover href="{{ public_route('register') }}">{{ __('Create a site account') }}</a>
            @endif
        </div>
    </div>
</section>
@endsection
