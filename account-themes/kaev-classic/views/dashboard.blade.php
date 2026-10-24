@extends('account-theme::layouts.app')
@section('title', __('Overview'))
@push('preload')
    <link rel="preload" href="{{ account_theme_asset('assets/images/banner.webp') }}" as="image" fetchpriority="high">
@endpush
@section('content')
@php
    $canCreateGameAccount = $settings['enabled'] && $quotaAccountCount < $settings['max_accounts'] && $availableServers > 0;
    $serverStatuses ??= [];
    $gameAccounts ??= collect();
    $availableRewardCount ??= 0;
    $topUpUrl ??= null;
    $statusLabels = [
        'online' => __('Available'),
        'maintenance' => __('Maintenance'),
        'offline' => __('Unavailable'),
    ];
@endphp
<div class="kc-overview">
    <section class="kc-banner" aria-labelledby="kc-banner-title">
        <div class="kc-banner-copy">
            <span class="kc-banner-site">{{ site_name() }}</span>
            <h2 id="kc-banner-title">{{ __('Hello, :name!', ['name' => $user->name]) }}</h2>
            @if ($serverStatuses !== [])
                <ul class="kc-banner-servers" aria-label="{{ __('Server status') }}">
                    @foreach ($serverStatuses as $statusServer)
                        <li>
                            <span @class(['account-server-state', $statusServer['availability_state']])><i aria-hidden="true"></i>{{ $statusServer['name'] }} — {{ $statusLabels[$statusServer['availability_state']] ?? __('Status pending') }}</span>
                            @if ($statusServer['public_players'] !== null)
                                <small>{{ __('Online') }}: <strong>{{ format_number($statusServer['public_players']) }}</strong></small>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="kc-banner-actions">
                @if ($topUpUrl !== null)
                    <a wire:navigate class="account-button primary" href="{{ $topUpUrl }}">{{ __('Top up balance') }}</a>
                @endif
                <a class="account-button secondary" href="{{ page_key_url('downloads') }}">{{ __('Download client') }}</a>
            </div>
        </div>
        <div class="kc-banner-art" aria-hidden="true">
            <img class="kc-banner-art-light" src="{{ account_theme_asset('assets/images/banner.webp') }}" alt="" width="1600" height="900" decoding="async" fetchpriority="high" data-kc-reveal>
            <img class="kc-banner-art-dark" src="{{ account_theme_asset('assets/images/banner-dark.webp') }}" alt="" width="1672" height="941" decoding="async" loading="lazy" data-kc-reveal>
            <canvas class="kc-banner-particles" data-kc-banner-particles hidden></canvas>
        </div>
        <button type="button" class="kc-effects-toggle" data-kc-effects-toggle hidden
            aria-label="{{ __('Banner effects') }}" aria-pressed="false"
            data-label-enable="{{ __('Enable banner effects') }}"
            data-label-disable="{{ __('Disable banner effects') }}"
            data-label-reduced="{{ __('Banner effects are disabled by your motion settings') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.2 6.8L21 12l-6.8 2.2L12 21l-2.2-6.8L3 12l6.8-2.2zM20 2v4M18 4h4"></path></svg>
        </button>
    </section>

    <section class="kc-tiles" aria-label="{{ __('Account summary') }}">
        <a wire:navigate.hover class="kc-tile" href="{{ public_route('wallet.index') }}">
            <span class="kc-tile-icon kc-tile-coin"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
            <span class="kc-tile-copy"><strong>{{ $walletView['label'] }}</strong><small>{{ __('Balance') }}</small></span>
        </a>
        <a wire:navigate.hover class="kc-tile" href="{{ public_route('game-accounts.index') }}">
            <span class="kc-tile-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"></circle><path d="M11 12l9-9M17 6l3 3"></path></svg></span>
            <span class="kc-tile-copy"><strong>{{ $quotaAccountCount }} / {{ $settings['max_accounts'] }}</strong><small>{{ __('Game accounts') }}</small></span>
        </a>
        <a wire:navigate.hover class="kc-tile" href="{{ public_route('web-inventory.index') }}">
            <span class="kc-tile-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 7l9-4 9 4v10l-9 4-9-4z"></path><path d="M3 7l9 4 9-4M12 11v10"></path></svg></span>
            <span class="kc-tile-copy"><strong>{{ format_number($availableRewardCount) }}</strong><small>{{ __('Web inventory') }}</small></span>
        </a>
    </section>

    <div class="kc-columns">
        <x-account-panel class="kc-waiting">
            <h2 class="kc-panel-title">{{ __('Waiting for you') }}</h2>
            @if ($availableRewardCount === 0 && $quotaAccountCount > 0)
                <p class="kc-muted">{{ __('Nothing is waiting for you right now.') }}</p>
            @endif
            <ul class="kc-list">
                @if ($quotaAccountCount === 0)
                    <li class="kc-list-row">
                        <span class="kc-list-copy"><strong>{{ __('Create your first game account') }}</strong>
                            @unless ($canCreateGameAccount)<small>{{ __('Game account creation is currently unavailable.') }}</small>@endunless
                        </span>
                        @if ($canCreateGameAccount)
                            <a wire:navigate.hover class="account-button secondary" href="{{ public_route('game-accounts.create') }}">{{ __('Create game account') }}</a>
                        @endif
                    </li>
                @endif
                @if ($availableRewardCount > 0)
                    <li class="kc-list-row">
                        <span class="kc-list-mark">{{ $availableRewardCount }}</span>
                        <span class="kc-list-copy"><strong>{{ __('Items in the web inventory: :count', ['count' => format_number($availableRewardCount)]) }}</strong><small>{{ __('Transfer them to a character to receive them in the game.') }}</small></span>
                        <a wire:navigate.hover class="account-button secondary" href="{{ public_route('web-inventory.index') }}">{{ __('Transfer to a character') }}</a>
                    </li>
                @endif
            </ul>
        </x-account-panel>

        <x-account-panel class="kc-accounts">
            <div class="kc-panel-head">
                <h2 class="kc-panel-title">{{ __('My game accounts') }}</h2>
                @if ($canCreateGameAccount && $quotaAccountCount > 0)
                    <a wire:navigate.hover class="account-button secondary" href="{{ public_route('game-accounts.create') }}">{{ __('Create game account') }}</a>
                @endif
            </div>
            @if ($gameAccounts->isEmpty())
                <p class="kc-muted">{{ __('Game accounts will appear here after you create one.') }}</p>
            @else
                <ul class="kc-list">
                    @foreach ($gameAccounts as $account)
                        <li class="kc-list-row">
                            <x-game-account-icon />
                            <span class="kc-list-copy"><strong>{{ $account->game_login }}</strong><small>{{ $account->registrationGameServer?->nameFor() ?? $account->loginServer?->name }}</small></span>
                            <a wire:navigate.hover class="kc-link" href="{{ public_route('game-accounts.show', ['gameAccount' => $account->id]) }}">{{ __('Open') }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-account-panel>
    </div>

    @if ($hiddenAccountCount > 0)
        <div class="account-inline-warning">{{ __('Some game accounts are temporarily unavailable because their LoginServer has no configured GameServer. They remain safe and continue to count toward the account limit.') }}</div>
    @endif
</div>
@endsection
