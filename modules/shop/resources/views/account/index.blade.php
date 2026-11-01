@extends('account-theme::layouts.app')
@section('title', __('module-shop::messages.account_title'))
@section('description', __('module-shop::messages.account_description'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section reward-inventory-shell">
        <div class="account-section-heading reward-inventory-heading">
            <span class="account-chip">{{ __('module-shop::messages.account_title') }}</span>
            <div class="account-panel-actions">
                <a wire:navigate class="account-button secondary" href="{{ route('modules.shop.history') }}">{{ __('module-shop::messages.history_title') }}</a>
                <a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index') }}">{{ __('module-shop::messages.open_inventory') }}</a>
            </div>
        </div>
        @if(session('shop_purchased'))
            <div class="account-notice success" role="status">
                <span>{{ __('module-shop::messages.purchase_success') }}</span>
                <a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index', ['server' => $server?->id]) }}">{{ __('module-shop::messages.open_inventory') }}</a>
            </div>
        @endif
        @error('purchase')<div class="account-notice warning" role="alert">{{ $message }}</div>@enderror
        @if($servers->isEmpty())
            <x-account-empty class="account-empty reward-empty">
                <span class="account-empty-symbol" aria-hidden="true">◇</span>
                <h2>{{ __('module-shop::messages.account_empty_title') }}</h2>
                <p>{{ __('module-shop::messages.account_empty_description') }}</p>
            </x-account-empty>
        @else
            @if($servers->count() > 1)
                <nav class="reward-server-tabs account-tabs" aria-label="{{ __('Game servers') }}">
                    @foreach($servers as $choice)
                        <a wire:navigate @class(['active' => $choice->id === $server->id]) @if($choice->id === $server->id) aria-current="page" @endif href="{{ route('modules.shop.index', ['server' => $choice->id]) }}">{{ $choice->nameFor() }}</a>
                    @endforeach
                </nav>
            @endif
            <div class="account-section-heading reward-inventory-heading">
                <span class="account-chip">{{ $server->nameFor() }}</span>
                <span class="account-chip">{{ __('module-shop::messages.account_balance', ['amount' => $walletView['formatted']]) }}</span>
            </div>
            @if(! $canBuy)
                <div class="account-inline-warning" role="status">{{ __('module-shop::messages.account_sales_paused') }}</div>
            @elseif($walletView['blocked'])
                <div class="account-inline-warning" role="status">{{ __('module-shop::messages.account_wallet_blocked') }}</div>
            @endif
            @if($tabs->count() > 1)
                <nav class="reward-view-tabs account-tabs" aria-label="{{ __('module-shop::messages.account_tabs') }}">
                    @foreach($tabs as $choice)
                        <a wire:navigate @class(['active' => $choice->id === $tab->id]) @if($choice->id === $tab->id) aria-current="page" @endif href="{{ route('modules.shop.index', ['server' => $server->id, 'tab' => $choice->id]) }}">{{ $choice->nameFor() }}</a>
                    @endforeach
                </nav>
            @endif
            @if($tab)
                <section>
                    <x-account-panel-header :title="$tab->nameFor()" :description="__('module-shop::messages.account_delivery_hint')" />
                    <div class="shop-product-grid" data-shop-catalog>
                        @foreach($products as $product)
                            <article class="shop-product-card" data-shop-product="{{ $product->id }}">
                                @include('module-shop::partials.product-artwork', ['visual' => $productVisuals[$product->id] ?? []])
                                <div class="shop-product-card-body">
                                    <h3>{{ $product->nameFor() }}</h3>
                                    @include('module-shop::partials.product-description')
                                    @include('module-shop::partials.product-contents')
                                </div>
                                <div class="shop-product-card-footer">
                                    <strong class="shop-product-price">{{ __('module-shop::messages.account_price', ['count' => format_number($product->price_coins)]) }}</strong>
                                    @if($canBuy && ! $walletView['blocked'])
                                        <form method="POST" action="{{ route('modules.shop.checkout') }}">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            <input type="hidden" name="request_uuid" value="{{ $requestUuids[$product->id] }}">
                                            <button class="account-button primary" type="submit" @disabled($walletView['balance'] < $product->price_coins)>{{ __('module-shop::messages.account_buy') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                    @if($products->hasPages())
                        <nav class="simple-pagination" aria-label="{{ __('module-shop::messages.account_pages') }}">
                            @if($products->onFirstPage())
                                <span class="account-button ghost disabled">← {{ __('Back') }}</span>
                            @else
                                <a wire:navigate class="account-button ghost" href="{{ $products->previousPageUrl() }}">← {{ __('Back') }}</a>
                            @endif
                            @if($products->hasMorePages())
                                <a wire:navigate class="account-button ghost" href="{{ $products->nextPageUrl() }}">{{ __('Next') }} →</a>
                            @else
                                <span class="account-button ghost disabled">{{ __('Next') }} →</span>
                            @endif
                        </nav>
                    @endif
                </section>
            @endif
        @endif
    </x-account-panel>
</x-account-page>
@endsection
