@extends('account-theme::layouts.app')
@section('title', $account->game_login)
@section('inline-validation-errors', '1')
@section('content')
@php($gameServers = $account->loginServer->gameServers)
<section class="account-detail-hero">
    <div class="account-detail-hero-back">
        @if ($accountCount > 1)<a wire:navigate href="{{ public_route('game-accounts.index') }}">← {{ __('My accounts') }}</a>@endif
    </div>
    <div class="account-detail-identity">
        <x-game-account-icon :large="true" aria-hidden="true" />
        <div><span class="account-eyebrow">{{ __('Game account') }}</span><div class="account-title-row"><h1>{{ $account->game_login }}</h1>@if(is_array($summary))<span class="account-chip {{ $summary['status'] === 'active' ? 'success' : 'danger' }}">{{ $summary['status'] === 'active' ? __('Active') : __('Blocked') }}</span>@endif</div><p>{{ $gameServers->map(static fn ($gameServer): string => $gameServer->nameFor())->implode(' · ') }}</p></div>
    </div>
    @if ($canCreateAccount)<a wire:navigate.hover class="account-button secondary account-button-create" href="{{ public_route('game-accounts.create') }}">{{ __('Create game account') }}</a>@endif
</section>

<section class="account-summary-card">
    <dl>
        <div><dt>{{ $gameServers->count() > 1 ? __('Servers') : __('Server') }}</dt><dd>@forelse ($gameServers as $gameServer)<span>{{ $gameServer->nameFor() }}</span>@if (! $loop->last)<br>@endif @empty — @endforelse</dd></div>
        <div><dt>{{ __('Linked to CMS') }}</dt><dd>{{ $account->created_at?->format('d.m.Y H:i') }}</dd></div>
        <div><dt>{{ __('Created on server') }}</dt><dd>{{ is_array($summary) && $summary['created_at'] ? $summary['created_at'] : '—' }}</dd></div>
        <div><dt>{{ __('Available worlds') }}</dt><dd>{{ count($worlds) }}</dd></div>
    </dl>
    @if($summaryUnavailable)<div class="account-inline-warning">{{ __('Game account data is temporarily unavailable. The account link remains safe in the personal account.') }}</div>@endif
</section>

<section class="account-section account-detail-worlds-section">
    <div class="account-section-heading"><div><span class="account-eyebrow">{{ __('Characters') }}</span><h2>{{ __('Characters by world') }}</h2><p>{{ __('Characters linked to this login on every available GameServer.') }}</p></div></div>
    @forelse($worlds as $world)
        <article class="world-card">
            <header><div><span class="world-card-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M4 12h16M12 4a13 13 0 0 1 0 16M12 4a13 13 0 0 0 0 16"></path></svg></span><div><h3>{{ $world['server']->nameFor() }}</h3><p>{{ $world['server']->chronicle }} @if($world['server']->rates)· {{ $world['server']->rates }}@endif</p></div></div><span class="world-card-count">{{ count($world['characters']) }}</span></header>
            @if(!$world['available'])
                <div class="world-empty">{{ __('Character data is temporarily unavailable.') }}</div>
            @elseif($world['characters'] === [])
                <div class="world-empty">{{ __('No characters on this world.') }}</div>
            @else
                <div class="character-list">
                    @foreach($world['characters'] as $character)
                        <div class="character-row">
                            <x-game-character-avatar :character="$character" class="character-avatar" />
                            <div class="character-main"><strong>{{ $character['name'] }}</strong><small>{{ $character['class_name'] }} @if($character['clan'])· {{ $character['clan'] }}@endif</small>@if($character['created_at'])<small>{{ __('Created: :date', ['date' => $character['created_at']->format('d.m.Y')]) }}</small>@endif</div>
                            <div class="character-level"><span>{{ __('Level') }}</span><strong>{{ $character['level'] }}</strong></div>
                            <span class="online-state {{ $character['online'] ? 'online' : '' }}">{{ $character['online'] ? __('Online') : __('Offline') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>
    @empty
        <div class="account-empty small"><h3>{{ __('No available game worlds') }}</h3><p>{{ __('Ask the administrator to configure a GameServer connection.') }}</p></div>
    @endforelse
</section>

<section class="account-section password-section account-detail-password-section">
    <div class="account-section-heading"><div><span class="account-eyebrow">{{ __('Security') }}</span><h2>{{ __('Change game password') }}</h2><p>{{ __('Confirm the operation with the password from your personal account.') }}</p></div></div>
    <livewire:account.game-account-password-form :account-id="$account->id" />
</section>
@endsection
