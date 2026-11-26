@extends('account-theme::layouts.app')
@section('title', __('module-voting::messages.title'))
@section('description', __('module-voting::messages.account_description'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section account-surface">
        <x-account-panel-header title="KTreon" :description="__('module-voting::messages.vote_help')" />
        <nav class="reward-server-tabs account-tabs" aria-label="{{ __('Game servers') }}">
            @foreach($rewards as $reward)
                <a wire:navigate @class(['active' => $reward->id === $selected->id]) @if($reward->id === $selected->id) aria-current="page" @endif href="{{ route('modules.voting.index', ['server' => $reward->game_server_id]) }}">{{ $reward->gameServer->nameFor() }}</a>
            @endforeach
        </nav>
        @if($errors->any())
            <div class="account-inline-warning" role="alert">{{ $errors->first() }}</div>
        @endif
        <h3>{{ __('module-voting::messages.rewards') }}</h3>
        <div class="reward-history-list">
            @foreach($items as $item)
                <article class="reward-history-card">
                    <div class="reward-history-main">
                        @if($item['icon'])
                            <img class="reward-item-icon" src="{{ $item['icon'] }}" width="32" height="32" alt="">
                        @endif
                        <div><h3>{{ $item['name'] }}</h3><p>× {{ format_number($item['amount']) }}</p></div>
                    </div>
                </article>
            @endforeach
        </div>
        @if($accounts->isEmpty())
            <x-account-empty class="account-empty"><p>{{ __('module-voting::messages.no_accounts') }}</p></x-account-empty>
        @else
            <form method="POST" action="{{ route('modules.voting.invite') }}" class="account-form">
                @csrf
                <input type="hidden" name="game_server_id" value="{{ $selected->game_server_id }}">
                <label for="voting_game_account">{{ __('module-voting::messages.game_account') }}</label>
                <select id="voting_game_account" name="user_game_account_id" required>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->game_login }}</option>
                    @endforeach
                </select>
                <button class="account-button primary" type="submit">{{ __('module-voting::messages.vote') }}</button>
            </form>
        @endif
    </x-account-panel>
</x-account-page>
@endsection
