@extends('theme::layouts.app')

@section('title', __('module-referrals::messages.invitation_title').' — '.site_name())

@section('content')
<section class="auth-page">
    <div class="panel auth-card">
        <h1>{{ __('module-referrals::messages.invitation_title') }}</h1>
        <p class="muted">{{ __('module-referrals::messages.invitation_text', ['days' => $expiresInDays]) }}</p>
        <a class="button button-gold" href="{{ public_route('register') }}">{{ __('module-referrals::messages.invitation_register') }}</a>
        <form method="POST" action="{{ route('modules.referrals.cancel') }}">
            @csrf
            <button class="button" type="submit">{{ __('module-referrals::messages.invitation_cancel') }}</button>
        </form>
    </div>
</section>
@endsection
