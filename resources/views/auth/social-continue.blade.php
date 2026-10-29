@extends('theme::layouts.app')
@section('title', __('Social sign-in').' — '.site_name())
@section('content')
<section class="auth-page">
    <div class="panel auth-card">
        <h1>{{ __('Social sign-in') }}</h1>
        <a class="button button-gold" href="{{ $continueUrl }}" data-social-continue>{{ $continueLabel }}</a>
    </div>
</section>
<script src="{{ asset('assets/js/social-continue.js') }}" defer></script>
@endsection
