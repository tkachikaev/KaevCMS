@extends('admin.layouts.panel')
@section('title', __('module-promo-codes::messages.journal_title'))
@section('description', __('module-promo-codes::messages.journal_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<div class="admin-actions-panel">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.promo-codes.index', ['adminPath' => $adminPath]) }}">← {{ __('module-promo-codes::messages.back_to_codes') }}</a>
</div>

@if($activations->isEmpty())
    <div class="admin-empty-state empty-state">
        <div class="empty-state-mark">0</div>
        <h2>{{ __('module-promo-codes::messages.journal_empty_title') }}</h2>
        <p>{{ __('module-promo-codes::messages.journal_empty_description') }}</p>
    </div>
@else
    <div class="admin-card-list content-list">
        @foreach($activations as $activation)
            <article class="admin-card-row content-row">
                <div class="content-row-preview page-row-preview"><span>✓</span></div>
                <div class="content-row-main">
                    <strong class="content-row-title">{{ $activation->code_snapshot }}</strong>
                    <p>{{ $activation->user?->name ?? $activation->user_email }} · {{ $activation->user_email }}</p>
                    <div class="content-row-meta">
                        <span>{{ $activation->gameServer->nameFor() }}</span>
                        <span>{{ $activation->activated_at?->format('d.m.Y H:i:s') }}</span>
                        <span>{{ __('module-promo-codes::messages.grant_number', ['id' => $activation->reward_inventory_grant_id ?? '—']) }}</span>
                    </div>
                    <div class="content-row-meta">
                        @foreach($activation->rewardGrant?->items ?? [] as $reward)
                            <span>{{ $reward->displayName($activation->game_server_id) }} × {{ number_format($reward->amount, 0, '.', ' ') }} <small>ID {{ $reward->item_id }}</small></span>
                        @endforeach
                    </div>
                </div>
                <div class="content-row-publication"><span class="publication-state published">{{ __('module-promo-codes::messages.activated') }}</span></div>
            </article>
        @endforeach
    </div>

    @if($activations->hasPages())
        <nav class="simple-pagination" aria-label="{{ __('module-promo-codes::messages.journal_pagination') }}">
            <a wire:navigate @class(['button button-secondary', 'disabled' => $activations->onFirstPage()]) href="{{ $activations->previousPageUrl() ?? '#' }}">← {{ __('module-promo-codes::messages.previous') }}</a>
            <span>{{ __('module-promo-codes::messages.page_of', ['current' => $activations->currentPage(), 'last' => $activations->lastPage()]) }}</span>
            <a wire:navigate @class(['button button-secondary', 'disabled' => ! $activations->hasMorePages()]) href="{{ $activations->nextPageUrl() ?? '#' }}">{{ __('module-promo-codes::messages.next') }} →</a>
        </nav>
    @endif
@endif
@endsection
