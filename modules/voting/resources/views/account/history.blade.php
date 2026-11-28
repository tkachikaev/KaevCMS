@extends('account-theme::layouts.app')
@section('title', __('module-voting::messages.history'))
@section('description', __('module-voting::messages.history_help'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section account-surface">
        <x-account-panel-header :title="__('module-voting::messages.history')" :description="__('module-voting::messages.history_help')">
            <x-slot:actions>
                @if($votingAvailable)
                    <a wire:navigate class="account-button secondary" href="{{ route('modules.voting.index') }}">{{ __('module-voting::messages.vote') }}</a>
                @endif
                <a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index') }}">{{ __('module-voting::messages.open_inventory') }}</a>
            </x-slot:actions>
        </x-account-panel-header>
        @if($errors->has('voting'))
            <div class="account-inline-warning" role="alert">{{ $errors->first('voting') }}</div>
        @endif
        @unless($votingAvailable)
            <div class="account-inline-warning" role="status">{{ __('module-voting::messages.voting_paused') }}</div>
        @endunless
        <nav class="reward-view-tabs account-tabs" aria-label="{{ __('module-voting::messages.vote_status') }}">
            @foreach(['' => 'all', 'pending' => 'status_pending', 'accepted' => 'status_accepted', 'rejected' => 'status_rejected', 'revoked' => 'status_revoked'] as $choice => $label)
                <a wire:navigate @class(['active' => $status === $choice]) @if($status === $choice) aria-current="page" @endif href="{{ route('modules.voting.history', $choice === '' ? [] : ['status' => $choice]) }}">{{ __('module-voting::messages.'.$label) }}</a>
            @endforeach
        </nav>
        @if($votes->isEmpty())
            <x-account-empty class="account-empty"><p>{{ __('module-voting::messages.no_votes') }}</p></x-account-empty>
        @else
            <div class="reward-history-list">
                @foreach($votes as $vote)
                    <article class="reward-history-card">
                        <div class="reward-history-main">
                            <div>
                                <h3>{{ $vote->game_account_login ?? '—' }} · {{ $vote->gameServer?->nameFor() ?? '—' }}</h3>
                                <p>KTreon · {{ substr($vote->voted_at, 0, 19) }} UTC</p>
                                <p>{{ __('module-voting::messages.vote_status') }}: {{ __('module-voting::messages.status_'.$vote->status) }} · {{ __('module-voting::messages.reward_status') }}: {{ __('module-voting::messages.reward_'.$vote->reward_state) }}</p>
                                @if($vote->granted_at)
                                    <p>{{ __('module-voting::messages.granted_at') }}: {{ $vote->granted_at->copy()->utc()->format('Y-m-d H:i:s') }} UTC</p>
                                @endif
                                @include('module-voting::shared.reward-details', ['vote' => $vote])
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            {{ $votes->links() }}
        @endif
    </x-account-panel>
</x-account-page>
@endsection
