@extends('admin.layouts.panel')
@section('title', __('module-daily-rewards::messages.journal_title'))
@section('description', __('module-daily-rewards::messages.journal_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<form class="users-filters" method="GET" action="{{ route('admin.module-pages.daily-rewards.claims', ['adminPath' => $adminPath]) }}">
    <div class="form-group"><label for="search">{{ __('module-daily-rewards::messages.search') }}</label><input id="search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('module-daily-rewards::messages.search_placeholder') }}"></div>
    <div class="admin-row-actions"><button class="button button-primary" type="submit">{{ __('module-daily-rewards::messages.filter') }}</button><a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.daily-rewards.claims', ['adminPath' => $adminPath]) }}">{{ __('module-daily-rewards::messages.reset') }}</a></div>
</form>

@if($claims->isEmpty())
    <div class="admin-empty-state empty-box">{{ __('module-daily-rewards::messages.no_journal_entries') }}</div>
@else
    <div class="admin-card-list content-list">
        @foreach($claims as $claim)
            <article class="admin-card-row content-row">
                <div class="content-row-main">
                    <strong class="content-row-title">{{ $claim->user_email }}</strong>
                    <p>{{ $claim->game_account_login }} · {{ $claim->gameServer->nameFor() }}</p>
                    <div class="content-row-meta">
                        <span>{{ $claim->calendar->periodLabel() }} · {{ __('module-daily-rewards::messages.day_number', ['day' => $claim->day->day_number]) }}</span>
                        <span>{{ $claim->reward_date?->format('d.m.Y') }}</span>
                        <span>{{ $claim->claimed_at?->format('d.m.Y H:i') }}</span>
                    </div>
                    <div class="content-row-meta">
                        @foreach((array) $claim->items_snapshot as $item)
                            <span>{{ $item['name'] ?? __('module-daily-rewards::messages.unknown_item') }} × {{ number_format((int) ($item['amount'] ?? 0), 0, '.', ' ') }} <small>ID {{ (int) ($item['item_id'] ?? 0) }}</small></span>
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
