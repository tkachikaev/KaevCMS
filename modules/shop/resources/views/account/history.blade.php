@extends('account-theme::layouts.app')
@section('title', __('module-shop::messages.history_title'))
@section('description', __('module-shop::messages.history_description'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section reward-inventory-shell">
        <div class="account-section-heading reward-inventory-heading">
            <span class="account-chip">{{ __('module-shop::messages.history_title') }}</span>
            <a wire:navigate class="account-button secondary" href="{{ route('modules.shop.index') }}">{{ __('module-shop::messages.history_back') }}</a>
        </div>
        @if($purchases->isEmpty())
            <x-account-empty class="account-empty reward-empty">
                <span class="account-empty-symbol" aria-hidden="true">◇</span>
                <h2>{{ __('module-shop::messages.history_empty') }}</h2>
            </x-account-empty>
        @else
            <div class="reward-history-list">
                @foreach($purchases as $purchase)
                    <article class="reward-history-card">
                        <div class="reward-history-main">
                            <div>
                                <small><time datetime="{{ $purchase->created_at->toIso8601String() }}">{{ $purchase->created_at->format('d.m.Y H:i') }}</time> · {{ $purchase->gameServer?->nameFor() }}</small>
                                <h3>{{ $purchase->nameFor() }}</h3>
                                <p>{{ __('module-shop::messages.purchase_receipt', ['id' => format_number($purchase->id)]) }}</p>
                                <small>{{ __('module-shop::messages.purchase_status') }}</small>
                            </div>
                        </div>
                        <div class="reward-history-status">
                            <strong>{{ __('module-shop::messages.account_price', ['count' => format_number($purchase->price_coins)]) }}</strong>
                            <a wire:navigate class="account-button secondary" href="{{ route('modules.shop.history.show', ['purchase' => $purchase->id]) }}">{{ __('module-shop::messages.purchase_details') }}</a>
                        </div>
                    </article>
                @endforeach
            </div>
            @if($purchases->hasPages())
                <nav class="simple-pagination" aria-label="{{ __('module-shop::messages.purchase_pages') }}">
                    @if($purchases->onFirstPage())
                        <span class="account-button ghost disabled">← {{ __('Back') }}</span>
                    @else
                        <a wire:navigate class="account-button ghost" href="{{ $purchases->previousPageUrl() }}">← {{ __('Back') }}</a>
                    @endif
                    @if($purchases->hasMorePages())
                        <a wire:navigate class="account-button ghost" href="{{ $purchases->nextPageUrl() }}">{{ __('Next') }} →</a>
                    @else
                        <span class="account-button ghost disabled">{{ __('Next') }} →</span>
                    @endif
                </nav>
            @endif
        @endif
    </x-account-panel>
</x-account-page>
@endsection
