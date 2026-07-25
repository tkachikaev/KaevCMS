@extends('theme::layouts.app')

@section('title', __('Email verification').' — '.site_name())

@section('content')
<section class="auth-page">
    <div class="panel auth-card auth-message-card">
        <p class="eyebrow">{{ __('Security eyebrow') }}</p>
        <h1>{{ __('Verify your email') }}</h1>
        <p class="muted">{!! __('A verification link was sent to :email. Your account will open after you follow the link.', ['email' => '<strong>'.e(auth()->user()->email).'</strong>']) !!}</p>

        @if ($mailReady)
            <form method="POST" action="{{ public_route('verification.send') }}">
                @csrf
                <button class="button button-gold" type="submit">{{ __('Resend verification email') }}</button>
            </form>
        @else
            <p class="auth-info auth-info-error">{{ __('Email sending is temporarily unavailable. Contact the site administration.') }}</p>
        @endif

        <form method="POST" action="{{ public_route('logout') }}">
            @csrf
            <button class="button button-ghost" type="submit">{{ __('Sign out') }}</button>
        </form>
    </div>
</section>
@endsection
