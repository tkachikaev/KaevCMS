@extends('admin.layouts.panel')
@section('title', __('module-daily-rewards::messages.admin_title'))
@section('description', __('module-daily-rewards::messages.admin_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<div class="admin-overview content-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('module-daily-rewards::messages.total') }}</span><strong>{{ $totalCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-daily-rewards::messages.enabled_count') }}</span><strong>{{ $enabledCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-daily-rewards::messages.claims') }}</span><strong>{{ number_format($claimCount, 0, '.', ' ') }}</strong></div>
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.daily-rewards.claims', ['adminPath' => $adminPath]) }}">{{ __('module-daily-rewards::messages.open_journal') }}</a>
    @if($canManage)
        <a wire:navigate class="button button-primary" href="{{ route('admin.module-pages.daily-rewards.create', ['adminPath' => $adminPath]) }}">{{ __('module-daily-rewards::messages.create') }}</a>
    @endif
</div>

@if($calendars->isEmpty())
    <div class="admin-empty-state empty-state">
        <div class="empty-state-mark">31</div>
        <h2>{{ __('module-daily-rewards::messages.empty_title') }}</h2>
        <p>{{ __('module-daily-rewards::messages.empty_description') }}</p>
        @if($canManage)
            <a wire:navigate class="button button-primary" href="{{ route('admin.module-pages.daily-rewards.create', ['adminPath' => $adminPath]) }}">{{ __('module-daily-rewards::messages.create_first') }}</a>
        @endif
    </div>
@else
    <div class="admin-card-list content-list">
        @foreach($calendars as $calendar)
            @php($configuredDays = $calendar->days->where('enabled', true)->count())
            @php($itemCount = $calendar->days->sum(fn($day) => $day->items->count()))
            <article class="admin-card-row content-row">
                <div class="content-row-preview page-row-preview"><span>{{ str_pad((string) $calendar->month, 2, '0', STR_PAD_LEFT) }}</span></div>
                <div class="content-row-main">
                    <a wire:navigate class="content-row-title" href="{{ route('admin.module-pages.daily-rewards.edit', ['adminPath' => $adminPath, 'calendar' => $calendar]) }}">{{ $calendar->periodLabel() }}</a>
                    <p>{{ $calendar->gameServer->nameFor() }}</p>
                    <div class="content-row-meta">
                        <span>{{ __('module-daily-rewards::messages.configured_days', ['count' => $configuredDays]) }}</span>
                        <span>{{ __('module-daily-rewards::messages.reward_items_count', ['count' => $itemCount]) }}</span>
                        <span>{{ __('module-daily-rewards::messages.claims_count', ['count' => number_format($calendar->claims_count, 0, '.', ' ')]) }}</span>
                    </div>
                </div>
                <div class="content-row-publication">
                    <span @class(['publication-state', $calendar->enabled ? 'published' : 'draft'])>{{ $calendar->enabled ? __('module-daily-rewards::messages.active') : __('module-daily-rewards::messages.inactive') }}</span>
                </div>
                <div class="admin-row-actions content-row-actions">
                    <a wire:navigate class="button button-primary" href="{{ route('admin.module-pages.daily-rewards.edit', ['adminPath' => $adminPath, 'calendar' => $calendar]) }}">{{ $canManage ? __('module-daily-rewards::messages.edit') : __('module-daily-rewards::messages.view') }}</a>
                    @if($canManage)
                        <form method="POST" action="{{ route('admin.module-pages.daily-rewards.toggle', ['adminPath' => $adminPath, 'calendar' => $calendar]) }}">
                            @csrf
                            @method('PATCH')
                            <button class="button button-secondary" type="submit">{{ $calendar->enabled ? __('module-daily-rewards::messages.disable') : __('module-daily-rewards::messages.enable') }}</button>
                        </form>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    @if($calendars->hasPages())
        <nav class="simple-pagination" aria-label="{{ __('module-daily-rewards::messages.calendar') }}">
            <a wire:navigate @class(['button button-secondary', 'disabled' => $calendars->onFirstPage()]) href="{{ $calendars->previousPageUrl() ?? '#' }}">← {{ __('module-daily-rewards::messages.previous') }}</a>
            <span>{{ __('module-daily-rewards::messages.page_of', ['current' => $calendars->currentPage(), 'last' => $calendars->lastPage()]) }}</span>
            <a wire:navigate @class(['button button-secondary', 'disabled' => ! $calendars->hasMorePages()]) href="{{ $calendars->nextPageUrl() ?? '#' }}">{{ __('module-daily-rewards::messages.next') }} →</a>
        </nav>
    @endif
@endif
@endsection
