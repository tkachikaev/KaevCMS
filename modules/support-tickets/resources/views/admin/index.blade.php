@extends('admin.layouts.panel')
@section('title', __('module-support-tickets::messages.admin_title'))
@section('description', __('module-support-tickets::messages.admin_description'))
@section('content')
@php($hasFilters = ($filters['q'] ?? '') !== '' || ($filters['status'] ?? '') !== '' || ($filters['category'] ?? '') !== '' || ($filters['assigned'] ?? '') !== '')
<div class="admin-overview content-toolbar support-ticket-stats">
    <div class="admin-overview-stat content-stat"><span>{{ __('module-support-tickets::messages.total_new') }}</span><strong>{{ $counts['new'] }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-support-tickets::messages.total_in_progress') }}</span><strong>{{ $counts['in_progress'] }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-support-tickets::messages.total_awaiting_player') }}</span><strong>{{ $counts['awaiting_player'] }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-support-tickets::messages.total_closed') }}</span><strong>{{ $counts['closed'] }}</strong></div>
    @if(auth('admin')->user()?->isOwner())
        <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.support-tickets.settings', ['adminPath' => $adminPath]) }}">{{ __('module-support-tickets::messages.settings') }}</a>
    @endif
</div>

<x-admin.filter-bar :action="route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath])" class="support-ticket-filters" data-testid="support-ticket-filters">
    <x-admin.field for="support-ticket-search" name="q" :label="__('module-support-tickets::messages.search')" class="support-ticket-search-field">
        <input id="support-ticket-search" name="q" type="search" maxlength="120" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('module-support-tickets::messages.search_placeholder') }}">
    </x-admin.field>
    <div>
        <label for="support-ticket-status">{{ __('module-support-tickets::messages.status') }}</label>
        <select id="support-ticket-status" name="status">
            <option value="">{{ __('module-support-tickets::messages.all_statuses') }}</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->adminLabel() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="support-ticket-category">{{ __('module-support-tickets::messages.category') }}</label>
        <select id="support-ticket-category" name="category">
            <option value="">{{ __('module-support-tickets::messages.all_categories') }}</option>
            @foreach($categories as $category)
                <option value="{{ $category->value }}" @selected(($filters['category'] ?? '') === $category->value)>{{ $category->label() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="support-ticket-assigned">{{ __('module-support-tickets::messages.assigned_to') }}</label>
        <select id="support-ticket-assigned" name="assigned">
            <option value="">{{ __('module-support-tickets::messages.all_assignments') }}</option>
            <option value="mine" @selected(($filters['assigned'] ?? '') === 'mine')>{{ __('module-support-tickets::messages.assigned_to_me') }}</option>
            <option value="unassigned" @selected(($filters['assigned'] ?? '') === 'unassigned')>{{ __('module-support-tickets::messages.unassigned') }}</option>
        </select>
    </div>
    <x-admin.button type="submit" variant="primary">{{ __('module-support-tickets::messages.apply_filters') }}</x-admin.button>
    @if($hasFilters)
        <x-admin.button wire:navigate :href="route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath])">{{ __('module-support-tickets::messages.reset_filters') }}</x-admin.button>
    @endif
</x-admin.filter-bar>

@if($tickets->isEmpty())
    <div class="admin-empty-state empty-state"><div class="empty-state-mark">?</div><h2>{{ __('module-support-tickets::messages.no_admin_tickets_title') }}</h2><p>{{ __('module-support-tickets::messages.no_admin_tickets_description') }}</p>@if($hasFilters)<x-admin.button wire:navigate :href="route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath])">{{ __('module-support-tickets::messages.reset_filters') }}</x-admin.button>@endif</div>
@else
    <div class="admin-card-list content-list support-admin-ticket-list">
        @foreach($tickets as $ticket)
            <article class="admin-card-row content-row support-admin-ticket-row">
                <div class="content-row-preview page-row-preview"><span>{{ $ticket->number() }}</span></div>
                <div class="content-row-main">
                    <a wire:navigate class="content-row-title" href="{{ route('admin.module-pages.support-tickets.show', ['adminPath' => $adminPath, 'ticket' => $ticket]) }}">{{ $ticket->subject }}</a>
                    <p>{{ $ticket->user_name_snapshot }} · {{ $ticket->user_email_snapshot }}</p>
                    <div class="content-row-meta"><span>{{ $ticket->category->label() }}</span><span>{{ __('module-support-tickets::messages.assigned_to') }}: {{ $ticket->assignedAdmin?->name ?? __('module-support-tickets::messages.not_assigned') }}</span><span>{{ __('module-support-tickets::messages.last_activity') }}: {{ $ticket->last_message_at->format('d.m.Y H:i') }}</span></div>
                </div>
                <div class="content-row-publication"><span class="support-ticket-status {{ $ticket->status->cssClass() }}">{{ $ticket->status->adminLabel() }}</span></div>
                <div class="admin-row-actions content-row-actions"><a wire:navigate class="button button-primary" href="{{ route('admin.module-pages.support-tickets.show', ['adminPath' => $adminPath, 'ticket' => $ticket]) }}">{{ __('module-support-tickets::messages.open_ticket') }}</a></div>
            </article>
        @endforeach
    </div>
    @if($tickets->hasPages())
        <nav class="simple-pagination" aria-label="{{ __('module-support-tickets::messages.pagination') }}"><a wire:navigate @class(['button button-secondary', 'disabled' => $tickets->onFirstPage()]) href="{{ $tickets->previousPageUrl() ?? '#' }}">← {{ __('module-support-tickets::messages.previous') }}</a><span>{{ __('module-support-tickets::messages.page_of', ['current' => $tickets->currentPage(), 'last' => $tickets->lastPage()]) }}</span><a wire:navigate @class(['button button-secondary', 'disabled' => ! $tickets->hasMorePages()]) href="{{ $tickets->nextPageUrl() ?? '#' }}">{{ __('module-support-tickets::messages.next') }} →</a></nav>
    @endif
@endif
@endsection
@push('head')<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>@endpush
@push('scripts')<script src="{{ asset('assets/modules/support-tickets.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>@endpush
