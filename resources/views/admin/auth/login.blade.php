@extends('admin.layouts.app')

@section('title', __('Administrator sign in'))

@section('body')
<main class="login-shell">
    <section class="login-panel" aria-labelledby="login-title">
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
            <p class="eyebrow">{{ __('SECURE AREA') }}</p>
            <h1 id="login-title">{{ __('Administrator sign in') }}</h1>
            <p>{{ __('Use a separate CMS administrator account. A game account cannot be used here.') }}</p>
        </div>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.store') }}" class="login-form">
            @csrf

            <label for="email">{{ __('Email address') }}</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                autocomplete="username"
                inputmode="email"
                maxlength="255"
                required
                autofocus
                @class(['field-error' => $errors->has('email')])
            >
            @error('email')<p class="error-text">{{ $message }}</p>@enderror

            <label for="password">{{ __('Password') }}</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                maxlength="4096"
                required
                @class(['field-error' => $errors->has('password')])
            >
            @error('password')<p class="error-text">{{ $message }}</p>@enderror

            <label class="remember-row" for="remember">
                <input id="remember" name="remember" type="checkbox" value="1" @checked(old('remember'))>
                <span>{{ __('Remember this device') }}</span>
            </label>

            <button type="submit" class="primary-button">{{ __('Sign in to control panel') }}</button>
        </form>

        <p class="login-note">{{ __('Sign-in attempts are rate-limited and written to the security log.') }}</p>
    </section>

    <aside class="login-art" aria-hidden="true">
        <div class="ornament"></div>
        <div class="art-content">
            <span>KAEVCMS</span>
            <strong>{{ __('Project management') }}</strong>
            <p>{{ __('News, settings, themes and modules are managed from one control panel.') }}</p>
        </div>
    </aside>
</main>
@endsection
