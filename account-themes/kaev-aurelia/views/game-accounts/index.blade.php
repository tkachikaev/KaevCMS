@extends('account-theme::layouts.app')
@section('title', __('Game accounts'))
@section('content')
<section class="account-page-hero">
    <div>
        <span class="account-eyebrow">{{ __('Game access') }}</span>
        <h1>{{ __('My accounts') }}</h1>
        <p>{{ __('Manage logins, passwords and characters connected to each game world.') }}</p>
    </div>
    @if ($settings['enabled'] && $quotaAccountCount < $settings['max_accounts'] && $availableServers > 0)
        <a wire:navigate.hover class="account-button primary account-button-create" href="{{ public_route('game-accounts.create') }}"><span aria-hidden="true">＋</span>{{ __('Create game account') }}</a>
    @endif
</section>

<section class="account-metrics account-metrics-compact">
    <article><span class="account-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="4"></rect><path d="M8 12h4M10 10v4M16.5 10.5h.01M18 13h.01"></path></svg></span><div><small>{{ __('Used slots') }}</small><strong>{{ $quotaAccountCount }} / {{ $settings['max_accounts'] }}</strong></div></article>
    <article><span class="account-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M4 12h16M12 4a13 13 0 0 1 0 16M12 4a13 13 0 0 0 0 16"></path></svg></span><div><small>{{ __('Available worlds') }}</small><strong>{{ $availableServers }}</strong></div></article>
</section>

@if ($hiddenAccountCount > 0)
    <div class="account-inline-warning">{{ __('Some game accounts are temporarily unavailable because their LoginServer has no configured GameServer. They remain safe and continue to count toward the account limit.') }}</div>
@endif

@if ($accounts->isEmpty())
    <div class="account-empty">
        <span class="account-empty-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"></path></svg></span>
        <h3>{{ __('No game accounts yet') }}</h3>
        <p>{{ __('Create the first account, then its characters will appear here.') }}</p>
        @if ($settings['enabled'] && $quotaAccountCount < $settings['max_accounts'] && $availableServers > 0)
            <a wire:navigate.hover class="account-button primary account-button-create" href="{{ public_route('game-accounts.create') }}">{{ __('Create game account') }}</a>
        @elseif ($availableServers === 0)
            <small>{{ __('No configured game servers are available for registration.') }}</small>
        @endif
    </div>
@else
    <div class="game-account-grid game-account-grid-wide">
        @foreach ($accounts as $account)
            @php($gameServers = $account->loginServer->gameServers)
            <article class="game-account-card">
                <div class="game-account-card-accent"></div>
                <div class="game-account-card-head">
                    <x-game-account-icon aria-hidden="true" />
                    <div><span>{{ __('Game account') }}</span><h3>{{ $account->game_login }}</h3></div>
                    <i aria-hidden="true"></i>
                </div>
                <dl>
                    <div><dt>{{ $gameServers->count() > 1 ? __('Servers') : __('Server') }}</dt><dd>@forelse ($gameServers as $gameServer)<span>{{ $gameServer->nameFor() }}</span>@if (! $loop->last)<br>@endif @empty — @endforelse</dd></div>
                    <div><dt>{{ __('Linked') }}</dt><dd>{{ $account->created_at?->format('d.m.Y') }}</dd></div>
                </dl>
                <a wire:navigate.hover class="account-card-link" href="{{ public_route('game-accounts.show', ['gameAccount' => $account]) }}"><span>{{ __('View details') }}</span><b aria-hidden="true">→</b></a>
            </article>
        @endforeach
    </div>
@endif
@endsection
