@extends('account-theme::layouts.app')
@section('title', __('module-shop::messages.purchase_receipt', ['id' => format_number($purchase->id)]))
@section('description', __('module-shop::messages.history_description'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section reward-inventory-shell">
        <div class="account-section-heading reward-inventory-heading">
            <span class="account-chip">{{ __('module-shop::messages.purchase_receipt', ['id' => format_number($purchase->id)]) }}</span>
            <a wire:navigate class="account-button secondary" href="{{ route('modules.shop.history') }}">{{ __('module-shop::messages.history_title') }}</a>
        </div>
        <x-account-panel-header :title="$purchase->nameFor()" :description="__('module-shop::messages.purchase_snapshot_hint')" />
        <div class="reward-history-list">
            <article class="reward-history-card">
                <div class="reward-history-main">
                    <div>
                        <small>{{ __('module-shop::messages.purchase_server') }}</small>
                        <h3>{{ $purchase->gameServer?->nameFor() }}</h3>
                        <p>{{ __('module-shop::messages.purchase_date') }}: <time datetime="{{ $purchase->created_at->toIso8601String() }}">{{ $purchase->created_at->format('d.m.Y H:i') }}</time></p>
                        <p>{{ __('module-shop::messages.purchase_status') }}</p>
                    </div>
                </div>
                <div class="reward-history-status">
                    <strong>{{ __('module-shop::messages.account_price', ['count' => format_number($purchase->price_coins)]) }}</strong>
                </div>
            </article>
        </div>
        <x-account-panel-header :title="__('module-shop::messages.purchase_contents')" />
        @include('module-shop::partials.purchase-items', ['purchase' => $purchase])
        <a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index', ['server' => $purchase->game_server_id]) }}">{{ __('module-shop::messages.open_inventory') }}</a>
    </x-account-panel>
</x-account-page>
@endsection
