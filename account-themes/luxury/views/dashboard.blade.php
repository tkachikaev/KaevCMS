@extends('account-theme::layouts.app')
@section('title', __('Personal account'))
@section('content')
<section class="account-hero account-hero-simple">
    <div class="account-hero-copy">
        <span class="account-eyebrow">{{ __('Player account') }}</span>
        <h1>{{ __('Welcome, :name', ['name' => $user->name]) }}</h1>
        <p>{{ __('Use the sections below to open characters, game accounts and rewards without browsing through nested server blocks.') }}</p>
        <div class="account-hero-actions">
            <a wire:navigate.hover class="account-button primary" href="{{ public_route('characters.index') }}">{{ __('Open characters') }}</a>
            @if ($settings['enabled'] && $quotaAccountCount < $settings['max_accounts'] && $availableServers > 0)
                <a wire:navigate.hover class="account-button secondary account-button-create" href="{{ public_route('game-accounts.create') }}"><span aria-hidden="true">＋</span>{{ __('Create game account') }}</a>
            @endif
        </div>
    </div>
    <div class="account-hero-profile" aria-hidden="true">
        <x-account-avatar :user="$user" class="account-dashboard-avatar" />
    </div>
</section>

<section class="account-metrics" aria-label="{{ __('Account summary') }}">
    <article>
        <span class="account-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="4"></rect><path d="M8 12h4M10 10v4M16.5 10.5h.01M18 13h.01"></path></svg></span>
        <div><small>{{ __('Game accounts') }}</small><strong>{{ $quotaAccountCount }} / {{ $settings['max_accounts'] }}</strong></div>
    </article>
    <article>
        <span class="account-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M4 12h16M12 4a13 13 0 0 1 0 16M12 4a13 13 0 0 0 0 16"></path></svg></span>
        <div><small>{{ __('Available worlds') }}</small><strong>{{ $availableServers }}</strong></div>
    </article>
    <article>
        <span class="account-metric-icon {{ $user->hasVerifiedEmail() ? 'success' : 'warning' }}" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="14" rx="3"></rect><path d="m5 8 7 5 7-5"></path></svg></span>
        <div><small>{{ __('Email status') }}</small><strong>{{ $user->hasVerifiedEmail() ? __('Verified') : __('Not verified') }}</strong></div>
    </article>
</section>

<section class="account-section account-dashboard-tools">
    <div class="account-section-heading">
        <div>
            <span class="account-eyebrow">{{ __('Quick access') }}</span>
            <h2>{{ __('Choose a section') }}</h2>
            <p>{{ __('The overview stays compact. Detailed characters, accounts and rewards are kept on separate pages.') }}</p>
        </div>
    </div>

    <div class="account-tool-grid">
        <a wire:navigate.hover href="{{ public_route('characters.index') }}" class="account-tool-card">
            <span class="account-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20a7 7 0 0 1 14 0M4 5l3-2M20 5l-3-2"></path></svg></span>
            <span><strong>{{ __('Characters') }}</strong><small>{{ __('Browse every character from all linked game accounts.') }}</small></span>
            <b aria-hidden="true">→</b>
        </a>
        <a wire:navigate.hover href="{{ public_route('game-accounts.index') }}" class="account-tool-card">
            <span class="account-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="4"></rect><path d="M8 12h4M10 10v4M16.5 10.5h.01M18 13h.01"></path></svg></span>
            <span><strong>{{ __('Game accounts') }}</strong><small>{{ __('Manage logins and change game passwords.') }}</small></span>
            <b aria-hidden="true">→</b>
        </a>
        <a wire:navigate.hover href="{{ public_route('web-inventory.index') }}" class="account-tool-card">
            <span class="account-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 8.5h16v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"></path><path d="M7 8.5V6a5 5 0 0 1 10 0v2.5M9 13h6"></path></svg></span>
            <span><strong>{{ __('Web inventory') }}</strong><small>{{ __('Review rewards and transfer them to a character.') }}</small></span>
            <b aria-hidden="true">→</b>
        </a>
    </div>
</section>

@if ($hiddenAccountCount > 0)
    <div class="account-inline-warning">{{ __('Some game accounts are temporarily unavailable because their LoginServer has no configured GameServer. They remain safe and continue to count toward the account limit.') }}</div>
@endif
@endsection
