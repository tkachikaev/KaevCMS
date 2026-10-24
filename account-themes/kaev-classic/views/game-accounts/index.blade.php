@extends('account-theme::layouts.app')
@section('title', __('My accounts'))
@section('description', __('Manage logins, passwords and characters connected to each game world.'))
@section('content')
<x-account-page>
<x-account-panel>
    <x-account-panel-header>
        <x-slot:summary>
            <div class="account-panel-stats">
                <x-account-stat :label="__('Used slots')" :value="$quotaAccountCount.' / '.$settings['max_accounts']" />
                <x-account-stat :label="__('Available worlds')" :value="$availableServers" />
            </div>
        </x-slot:summary>
        @if ($settings['enabled'] && $quotaAccountCount < $settings['max_accounts'] && $availableServers > 0)
            <x-slot:actions>
                <a wire:navigate.hover class="account-button primary account-button-create" href="{{ public_route('game-accounts.create') }}"><span aria-hidden="true">＋</span>{{ __('Create game account') }}</a>
            </x-slot:actions>
        @endif
    </x-account-panel-header>
@if ($hiddenAccountCount > 0)
    <div class="account-inline-warning">{{ __('Some game accounts are temporarily unavailable because their LoginServer has no configured GameServer. They remain safe and continue to count toward the account limit.') }}</div>
@endif

@if ($accounts->isEmpty())
    <x-account-empty class="account-empty">
        <span class="account-empty-symbol" aria-hidden="true">◇</span>
        <h3>{{ __('No game accounts yet') }}</h3>
        <p>{{ __('Create the first account, then its characters will appear here.') }}</p>
        @if ($availableServers === 0)
            <small>{{ __('No configured game servers are available for registration.') }}</small>
        @endif
    </x-account-empty>
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
</x-account-panel>
</x-account-page>
@endsection
