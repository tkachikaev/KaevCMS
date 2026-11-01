@extends('admin.layouts.panel')
@section('title', __('module-referrals::messages.reward_journal'))
@section('description', __('module-referrals::messages.reward_journal_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@include('module-referrals::admin.navigation')
<x-admin.filter-bar :action="route('admin.module-pages.referrals.grants.index', ['adminPath' => $adminPath])" class="users-filters">
    <div class="form-group"><label for="ref-reward-server">{{ __('module-referrals::messages.servers') }}</label><select id="ref-reward-server" name="server"><option value="">{{ __('module-referrals::messages.filter_all') }}</option>@foreach($servers as $server)<option value="{{ $server->id }}" @selected((int) ($filters['server'] ?? 0) === $server->id)>{{ $server->nameFor() }}</option>@endforeach</select></div>
    <div class="form-group"><label for="ref-reward-user">{{ __('module-referrals::messages.filter_recipient') }}</label><input id="ref-reward-user" type="number" min="1" name="user" value="{{ $filters['user'] ?? '' }}"></div>
    <div class="form-group"><label for="ref-reward-level">{{ __('module-referrals::messages.level') }}</label><input id="ref-reward-level" type="number" min="1" max="65535" name="level" value="{{ $filters['level'] ?? '' }}"></div>
    <div class="form-group"><label for="ref-reward-status">{{ __('module-referrals::messages.filter_status') }}</label><select id="ref-reward-status" name="status"><option value="">{{ __('module-referrals::messages.filter_all') }}</option>@foreach(['pending','earned','granted'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('module-referrals::messages.reward_status_'.$status) }}</option>@endforeach</select></div>
    <div class="admin-row-actions"><x-admin.button type="submit" variant="primary">{{ __('module-referrals::messages.filter_apply') }}</x-admin.button><x-admin.button wire:navigate :href="route('admin.module-pages.referrals.grants.index', ['adminPath' => $adminPath])">{{ __('module-referrals::messages.filter_reset') }}</x-admin.button></div>
</x-admin.filter-bar>
@if($rewards->isEmpty())
    <div class="admin-empty-state empty-state"><h2>{{ __('module-referrals::messages.no_history_title') }}</h2><p>{{ __('module-referrals::messages.no_history_description') }}</p></div>
@else
    <div class="admin-card-list content-list">
        @foreach($rewards as $reward)
            <article class="admin-card-row content-row">
                <div class="content-row-main">
                    <strong class="content-row-title">{{ $reward->gameServer?->nameFor() ?? '—' }} · {{ __('module-referrals::messages.level_required', ['level' => format_number($reward->level)]) }}</strong>
                    <p>{{ __('module-referrals::messages.filter_recipient') }}: #{{ format_number($reward->recipient_user_id) }} · {{ __('module-referrals::messages.item_id') }}: {{ format_number($reward->item_id) }} · × {{ format_number($reward->amount) }}</p>
                    <div class="content-row-meta">
                        <span>{{ __('module-referrals::messages.invitation_number', ['number' => format_number($reward->invite_id)]) }}</span>
                        <span>{{ __('module-referrals::messages.audience_'.$reward->audience) }}</span>
                        <span>{{ __('module-referrals::messages.reward_status_'.$reward->status) }}</span>
                        @if($reward->granted_at)<span>{{ $reward->granted_at->format('d.m.Y H:i') }}</span>@endif
                    </div>
                </div>
            </article>
        @endforeach
    </div>
    {{ $rewards->links() }}
@endif
@endsection
