@extends('account-theme::layouts.app')
@section('title', __('module-shop::messages.account_title'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section shop-storefront">
        <div class="shop-storefront-head">
            @if($servers->count() > 1)
                <nav class="account-tabs shop-server-tabs" aria-label="{{ __('Game servers') }}">
                    @foreach($servers as $choice)
                        <a wire:navigate.preserve-scroll @class(['active' => $choice->id === $server->id]) @if($choice->id === $server->id) aria-current="page" @endif href="{{ route('modules.shop.index', ['server' => $choice->id]) }}">{{ $choice->nameFor() }}</a>
                    @endforeach
                </nav>
            @endif
            <span class="shop-storefront-spacer"></span>
            <span class="shop-balance">
                <x-account-wallet-icon :url="$walletView['icon_url']" />
                {{ $walletView['label'] }}
            </span>
            <a wire:navigate class="account-button secondary" href="{{ route('modules.shop.history') }}">{{ __('module-shop::messages.history_title') }}</a>
        </div>

        @if(session('shop_purchased'))
            <div class="account-notice success" role="status">
                <span>{{ __('module-shop::messages.purchase_success') }}</span>
                <a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index', ['server' => $server?->id]) }}">{{ __('module-shop::messages.open_inventory') }}</a>
            </div>
        @endif
        @error('purchase')<div class="account-notice warning" role="alert">{{ $message }}</div>@enderror

        @if($servers->isEmpty())
            <x-account-empty class="account-empty shop-empty">
                <h2>{{ __('module-shop::messages.account_empty_title') }}</h2>
                <p>{{ __('module-shop::messages.account_empty_description') }}</p>
            </x-account-empty>
        @else
            @if(! $canBuy)
                <div class="account-inline-warning" role="status">{{ __('module-shop::messages.account_sales_paused') }}</div>
            @elseif($walletView['blocked'])
                <div class="account-inline-warning" role="status">{{ __('module-shop::messages.account_wallet_blocked') }}</div>
            @endif
            @if($tabs->count() > 1)
                <nav class="shop-section-pills" aria-label="{{ __('module-shop::messages.account_tabs') }}">
                    @foreach($tabs as $choice)
                        <a wire:navigate.preserve-scroll @class(['active' => $choice->id === $tab->id]) @if($choice->id === $tab->id) aria-current="page" @endif href="{{ route('modules.shop.index', ['server' => $server->id, 'tab' => $choice->id]) }}">{{ $choice->nameFor() }}</a>
                    @endforeach
                </nav>
            @endif
            @if($tab)
                <section aria-labelledby="shop-section-title">
                    <h2 id="shop-section-title" class="shop-section-title">{{ $tab->nameFor() }}</h2>
                    <div class="shop-product-grid" data-shop-catalog>
                        @foreach($products as $product)
                            @php($visual = $productVisuals[$product->id] ?? [])
                            @php($contents = $visual['contents'] ?? [])
                            @php($affordable = $walletView['balance'] >= $product->price_coins)
                            <article class="shop-product-card" data-shop-product="{{ $product->id }}">
                                @include('module-shop::partials.product-artwork', ['visual' => $visual])
                                <div class="shop-product-card-body">
                                    <h3>{{ $product->nameFor() }}</h3>
                                    @include('module-shop::partials.product-description')
                                    @include('module-shop::partials.product-contents', ['contents' => $contents])
                                </div>
                                <div class="shop-product-card-footer">
                                    <span class="shop-product-price"><x-account-wallet-icon :url="$walletView['icon_url']" />{{ format_number($product->price_coins) }}</span>
                                    @if($canBuy && ! $walletView['blocked'])
                                        <button type="button" class="account-button primary shop-buy-button" data-account-social-open="shop-buy-{{ $product->id }}" aria-haspopup="dialog" @disabled(! $affordable)>{{ __('module-shop::messages.account_buy') }}</button>
                                    @endif
                                </div>
                                @if($canBuy && ! $walletView['blocked'] && ! $affordable)
                                    <p class="shop-product-shortfall">{{ __('module-shop::messages.account_not_enough', ['count' => format_number($product->price_coins - $walletView['balance'])]) }}</p>
                                @endif
                            </article>
                            @if($canBuy && ! $walletView['blocked'] && $affordable)
                                @include('module-shop::account._buy-dialog')
                            @endif
                        @endforeach
                    </div>
                    @if($products->hasPages())
                        <nav class="simple-pagination" aria-label="{{ __('module-shop::messages.account_pages') }}">
                            @if($products->onFirstPage())
                                <span class="account-button ghost disabled">← {{ __('Back') }}</span>
                            @else
                                <a wire:navigate.preserve-scroll class="account-button ghost" href="{{ $products->previousPageUrl() }}">← {{ __('Back') }}</a>
                            @endif
                            @if($products->hasMorePages())
                                <a wire:navigate.preserve-scroll class="account-button ghost" href="{{ $products->nextPageUrl() }}">{{ __('Next') }} →</a>
                            @else
                                <span class="account-button ghost disabled">{{ __('Next') }} →</span>
                            @endif
                        </nav>
                    @endif
                    <p class="shop-delivery-hint">{{ __('module-shop::messages.account_delivery_hint') }}</p>
                </section>
            @endif
        @endif
    </x-account-panel>
</x-account-page>
@endsection
