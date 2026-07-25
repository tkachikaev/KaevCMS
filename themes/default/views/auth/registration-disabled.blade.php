@extends('theme::layouts.app')

@section('title', __('Registration disabled').' — '.site_name())

@section('content')
<section class="auth-page">
    <div class="panel auth-card auth-message-card">
        <p class="eyebrow">{{ __('Registration eyebrow') }}</p>
        <h1>{{ __('Registration disabled') }}</h1>
        <p class="muted">{{ $reason ?? __('The site administration has temporarily disabled new account registration.') }}</p>
        <a class="button button-gold" href="{{ public_route('home') }}">{{ __('Return to home') }}</a>
    </div>
</section>
@endsection
