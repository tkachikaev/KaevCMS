@extends('admin.layouts.panel')
@section('title', __('module-voting::messages.history'))
@section('description', __('module-voting::messages.history_help'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@include('module-voting::admin.navigation')
<x-admin.filter-bar :action="route('admin.module-pages.voting.history.index', ['adminPath' => $adminPath])" class="users-filters">
    <div class="form-group">
        <label for="voting_status">{{ __('module-voting::messages.vote_status') }}</label>
        <select id="voting_status" name="status">
            <option value="">{{ __('module-voting::messages.all') }}</option>
            @foreach(['pending', 'accepted', 'rejected', 'revoked'] as $choice)
                <option value="{{ $choice }}" @selected($status === $choice)>{{ __('module-voting::messages.status_'.$choice) }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label for="voting_account">{{ __('module-voting::messages.game_account') }}</label>
        <input id="voting_account" name="account" type="search" maxlength="190" value="{{ $account }}">
    </div>
    <div class="admin-row-actions"><button type="submit" class="button button-primary">{{ __('module-voting::messages.filter') }}</button></div>
</x-admin.filter-bar>
<x-admin.card>
    @if($votes->isEmpty())
        <div class="admin-empty-state empty-state"><p>{{ __('module-voting::messages.no_votes') }}</p></div>
    @else
        <div class="admin-card-list content-list">
            @foreach($votes as $vote)
                <article class="admin-card-row content-row">
                    <div class="content-row-main">
                        <strong>{{ $vote->game_account_login ?? '—' }} · {{ $vote->gameServer?->nameFor() ?? '—' }}</strong>
                        <p>KTreon · {{ substr($vote->voted_at, 0, 19) }} UTC</p>
                        <span>{{ __('module-voting::messages.status_'.$vote->status) }}</span>
                    </div>
                    <span @class(['status-badge', 'status-badge-success' => $vote->reward_inventory_grant_id !== null])>{{ __('module-voting::messages.reward_'.$vote->reward_state) }}</span>
                </article>
            @endforeach
        </div>
        {{ $votes->links() }}
    @endif
</x-admin.card>
@endsection
