@extends('admin.layouts.panel')
@section('title', __('module-daily-rewards::messages.journal_title'))
@section('description', __('module-daily-rewards::messages.journal_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<x-admin.filter-bar :action="route('admin.module-pages.daily-rewards.claims', ['adminPath' => $adminPath])" class="users-filters daily-reward-claim-filters">
    <div class="form-group"><label for="search">{{ __('module-daily-rewards::messages.search') }}</label><input id="search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('module-daily-rewards::messages.search_placeholder') }}"></div>
    <div class="admin-row-actions"><x-admin.button type="submit" variant="primary">{{ __('module-daily-rewards::messages.filter') }}</x-admin.button><x-admin.button wire:navigate :href="route('admin.module-pages.daily-rewards.claims', ['adminPath' => $adminPath])">{{ __('module-daily-rewards::messages.reset') }}</x-admin.button></div>
</x-admin.filter-bar>

@if($claims->isEmpty())
    <div class="admin-empty-state empty-box">{{ __('module-daily-rewards::messages.no_journal_entries') }}</div>
@else
    <div class="admin-card-list content-list">
        @foreach($claims as $claim)
            <article class="admin-card-row content-row" data-testid="daily-reward-claim-row">
                <div class="content-row-main">
                    <strong class="content-row-title">{{ $claim->user_email }}</strong>
                    <p>{{ $claim->game_account_login }} · {{ $claim->gameServer->nameFor() }} · {{ __('module-daily-rewards::messages.server_id', ['id' => $claim->game_server_id]) }}</p>
                    <div class="content-row-meta">
                        <span>{{ $claim->calendar->periodLabel() }} · {{ __('module-daily-rewards::messages.day_number', ['day' => $claim->day->day_number]) }}</span>
                        <span>{{ $claim->reward_date?->format('d.m.Y') }}</span>
                        <span>{{ $claim->claimed_at?->format('d.m.Y H:i') }}</span>
                    </div>
                    @if($claim->rewardGrant?->operation_uuid)
                        <div class="content-row-meta"><span>{{ __('module-daily-rewards::messages.operation_uuid') }}: <code>{{ $claim->rewardGrant->operation_uuid }}</code></span></div>
                    @endif
                    <div class="reward-journal-items">
                        @foreach($claimItems[$claim->id] ?? [] as $item)
                            <div class="reward-journal-item">
                                <span class="reward-journal-item-icon" aria-hidden="true">
                                    @if($item['icon_url'])<img src="{{ $item['icon_url'] }}" alt="" width="36" height="36">@else{{ mb_strtoupper(mb_substr($item['name'] ?: __('module-daily-rewards::messages.unknown_item'), 0, 1)) }}@endif
                                </span>
                                <span class="reward-journal-item-copy">
                                    <strong>{{ $item['name'] ?: __('module-daily-rewards::messages.unknown_item') }}</strong>
                                    <small>ID {{ $item['item_id'] }} · × {{ number_format($item['amount'], 0, '.', ' ') }}</small>
                                    @if($item['status'])<small>{{ __('module-daily-rewards::messages.inventory_status_'.$item['status']) }}</small>@endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="content-row-publication"><span class="publication-state published">{{ __('module-daily-rewards::messages.added_to_inventory') }}</span></div>
            </article>
        @endforeach
    </div>

    @if($claims->hasPages())
        <nav class="simple-pagination">
            <a wire:navigate @class(['button button-secondary', 'disabled' => $claims->onFirstPage()]) href="{{ $claims->previousPageUrl() ?? '#' }}">← {{ __('module-daily-rewards::messages.previous') }}</a>
            <span>{{ __('module-daily-rewards::messages.page_of', ['current' => $claims->currentPage(), 'last' => $claims->lastPage()]) }}</span>
            <a wire:navigate @class(['button button-secondary', 'disabled' => ! $claims->hasMorePages()]) href="{{ $claims->nextPageUrl() ?? '#' }}">{{ __('module-daily-rewards::messages.next') }} →</a>
        </nav>
    @endif
@endif
@endsection
