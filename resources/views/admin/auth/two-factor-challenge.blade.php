@extends('admin.layouts.app')

@section('title', __('Two-factor authentication'))

@section('body')
<main class="login-shell">
    <section class="login-panel" aria-labelledby="two-factor-title">
        <div class="login-brand-block">
            <div class="login-brand-top">
                <a class="login-brand-mark-link" href="{{ public_route('home') }}" aria-label="{{ __('Return to website') }}">
                    <span class="brand-mark">L2</span>
                </a>
                @include('admin.partials.language-switcher')
            </div>

            <a class="login-brand-copy" href="{{ public_route('home') }}">
                <strong>{{ config('app.name') }}</strong>
                <small>CONTROL PANEL</small>
            </a>
        </div>

        <div class="login-copy">
            <h1 id="two-factor-title">{{ __('Two-factor authentication') }}</h1>
            <p>{{ __('Enter the code from your authenticator app for :email.', ['email' => $administratorEmail]) }}</p>
        </div>

        <form method="POST" action="{{ route('admin.two-factor.challenge.store') }}" class="login-form">
            @csrf
            <label for="code">{{ __('Authentication or recovery code') }}</label>
            <input
                id="code"
                name="code"
                type="text"
                inputmode="text"
                autocomplete="one-time-code"
                maxlength="64"
                required
                autofocus
                @class(['field-error' => $errors->has('code')])
            >
            @error('code')<p class="error-text">{{ $message }}</p>@enderror

            <button type="submit" class="primary-button">{{ __('Verify and sign in') }}</button>
        </form>

        <form method="POST" action="{{ route('admin.two-factor.challenge.cancel') }}" class="two-factor-cancel-form">
            @csrf
            <button class="button button-secondary" type="submit">{{ __('Cancel and return to sign in') }}</button>
        </form>

        <p class="login-note">{{ __('You can use one unused recovery code if the authenticator app is unavailable.') }}</p>
    </section>
</main>
@endsection
