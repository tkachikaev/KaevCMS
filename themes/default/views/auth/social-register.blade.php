@extends('theme::layouts.app')
@section('title', __('Complete Google registration').' — '.site_name())
@section('content')
<section class="auth-page">
    <div class="panel auth-card">
        <h1>{{ __('Complete Google registration') }}</h1>
        @if (session('status')) <p class="auth-info" role="status">{{ session('status') }}</p> @endif
        @if ($errors->has('social')) <p class="auth-info" role="alert">{{ $errors->first('social') }}</p> @endif
        @if (! $pending['assured'])
            <p>{{ __('Verify your email address before creating a CMS account.') }}</p>
            <form method="POST" action="{{ route('social.google.email.send') }}">
                @csrf
                <label for="social_email">{{ __('Email') }}
                    <input id="social_email" type="email" name="email" maxlength="255" value="{{ old('email', $pending['email']) }}" required autocomplete="email">
                </label>
                @error('email')<p role="alert">{{ $message }}</p>@enderror
                <button class="button button-gold" type="submit">{{ __('Send verification email') }}</button>
            </form>
        @else
            <p>{{ __('Email') }}: {{ $pending['email'] }}</p>
            <p>{{ __('Choose a username for your CMS account.') }}</p>
            <form method="POST" action="{{ route('social.google.register.store') }}">
                @csrf
                <label for="name">{{ __('Login') }}
                    <input id="name" name="name" type="text" minlength="{{ $registrationPolicy['username_min'] }}" maxlength="{{ $registrationPolicy['username_max'] }}" pattern="{{ $usernamePattern }}" required autofocus autocomplete="username" value="{{ old('name') }}">
                    <small>{{ implode(' ', $usernameRequirements) }}</small>
                </label>
                @error('name')<p role="alert">{{ $message }}</p>@enderror
                <button class="button button-gold" type="submit">{{ __('Create account') }}</button>
            </form>
        @endif
        <div class="auth-links"><a href="{{ public_route('login') }}">{{ __('Back to sign in') }}</a></div>
    </div>
</section>
@endsection
