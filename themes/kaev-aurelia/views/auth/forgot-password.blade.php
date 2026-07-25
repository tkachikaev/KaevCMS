@extends('theme::layouts.app')

@section('title', __('Password recovery').' — '.site_name())

@section('content')
<section class="auth-page">
    <div class="panel auth-card">
        <p class="eyebrow">{{ __('ACCESS RECOVERY') }}</p>
        <h1>{{ __('Forgot your password') }}</h1>
        <p class="muted">{{ __('Enter the account email. We will send a link for setting a new password.') }}</p>

        <form method="POST" action="{{ public_route('password.email') }}">
            @csrf
            <label for="email">{{ __('Email') }}
                <input id="email" name="email" type="email" maxlength="255" required autofocus autocomplete="email" value="{{ old('email') }}">
            </label>
            <button class="button button-gold" type="submit">{{ __('Send reset link') }}</button>
        </form>

        <div class="auth-links auth-links-center">
            <a wire:navigate.hover href="{{ public_route('login') }}">{{ __('Return to sign in') }}</a>
        </div>
    </div>
</section>
@endsection
