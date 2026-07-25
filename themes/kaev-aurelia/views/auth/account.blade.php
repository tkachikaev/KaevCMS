@extends('theme::layouts.app')

@section('title', __('Personal account').' — '.site_name())

@section('content')
<section class="page-hero"><div class="container"><p class="eyebrow">{{ __('SITE ACCOUNT') }}</p><h1>{{ __('Personal account') }}</h1></div></section>
<section class="container page-content account-page">
    <div class="panel account-card">
        <div class="account-heading">
            <div>
                <span class="account-label">{{ __('User') }}</span>
                <h2>{{ $user->name }}</h2>
            </div>
            <span class="account-status {{ $user->hasVerifiedEmail() ? 'verified' : 'unverified' }}">
                {{ $user->hasVerifiedEmail() ? __('Email verified') : __('Email not verified') }}
            </span>
        </div>

        <dl class="account-details">
            <div><dt>{{ __('Login') }}</dt><dd>{{ $user->name }}</dd></div>
            <div><dt>{{ __('Email') }}</dt><dd>{{ $user->email }}</dd></div>
            <div><dt>{{ __('Registration date') }}</dt><dd>{{ $user->created_at?->format('d.m.Y H:i') }}</dd></div>
            <div><dt>{{ __('Language') }}</dt><dd>{{ $enabledLanguages[$user->locale]['native_name'] ?? strtoupper((string) $user->locale) }}</dd></div>
            <div><dt>{{ __('Game account') }}</dt><dd>{{ __('Created separately') }}</dd></div>
        </dl>

        <div class="account-note">
            {{ __('The site account is not a Lineage II game account. Game accounts will be connected separately.') }}
        </div>
    </div>
</section>
@endsection
