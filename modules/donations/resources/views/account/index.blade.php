@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.account_title'))
@section('description', __('module-donations::messages.account_description'))
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/donations-player.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@section('content')
<x-account-page>

<x-account-panel class="account-section account-donations-card account-donations-calculator" data-testid="donations-player-calculator" data-donations-calculator>
    <x-account-panel-header :title="__('module-donations::messages.choose_coins')" :description="__('module-donations::messages.player_calculator_help')">
        <x-slot:actions><a wire:navigate class="account-button secondary" href="{{ route('modules.donations.orders.index') }}">{{ __('module-donations::messages.orders_title') }}</a></x-slot:actions>
    </x-account-panel-header>
    @if($currencies === [])
        <p data-testid="donations-no-currencies">{{ __('module-donations::messages.player_no_currencies') }}</p>
    @else
        <form method="GET" action="{{ route('modules.donations.index') }}" class="account-form-card" data-testid="donations-quote-form">
            <div class="account-form-grid">
                <label for="donations-player-coins">
                    <span>{{ __('module-donations::messages.player_coin_quantity') }}</span>
                    <div class="account-field-control">
                        <input id="donations-player-coins" type="number" inputmode="numeric" min="{{ $limits['min_coins'] }}" max="{{ $limits['max_coins'] }}" step="1" name="coins" value="{{ $coinsInput }}" required>
                    </div>
                </label>
                <label for="donations-player-currency">
                    <span>{{ __('module-donations::messages.currency') }}</span>
                    <div class="account-field-control">
                        <select id="donations-player-currency" name="currency" required>
                            @foreach($currencies as $currency)
                                <option value="{{ $currency['code'] }}" data-price-minor="{{ $currency['unit_price_minor'] }}" data-exponent="{{ $currency['exponent'] }}" @selected($currency['code'] === $selectedCurrency)>{{ $currency['code'] }} — {{ $currency['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </label>
            </div>
            <div class="donations-quantity-range" data-donations-range-control hidden>
                <label for="donations-player-range"><span>{{ __('module-donations::messages.player_quantity_slider') }}</span></label>
                <input id="donations-player-range" type="range" min="{{ $limits['min_coins'] }}" max="{{ $limits['max_coins'] }}" step="1" value="{{ $coinsInput ?: $limits['min_coins'] }}" aria-describedby="donations-player-limits">
                <div class="donations-range-labels" aria-hidden="true"><span>{{ $limits['min_coins'] }}</span><span>{{ $limits['max_coins'] }}</span></div>
            </div>
            <p id="donations-player-limits">{{ __('module-donations::messages.player_coin_limits', ['min' => $limits['min_coins'], 'max' => $limits['max_coins']]) }}</p>
            <p data-donations-live-help hidden>{{ __('module-donations::messages.player_live_total') }}</p>
            <div class="account-form-actions" data-donations-calculate-fallback>
                <button class="account-button primary" type="submit">{{ __('module-donations::messages.player_calculate') }}</button>
            </div>
        </form>
        <p class="account-field-error" role="alert" data-testid="donations-quote-error" data-invalid-coins="{{ __('module-donations::messages.player_invalid_coins') }}" data-invalid-currency="{{ __('module-donations::messages.player_invalid_currency') }}" @if($quoteError === null) hidden @endif>{{ $quoteError }}</p>
        <div class="account-wallet-summary" data-testid="donations-quote-result" aria-live="polite" aria-atomic="true" @if($quote === null) hidden @endif>
            <div class="account-wallet-summary-text">
                <span class="account-eyebrow">{{ __('module-donations::messages.player_estimated_total') }}</span>
                <h2 data-donations-total>{{ $quote !== null ? $money->format($quote['total_minor'], $quote['exponent']).' '.$quote['currency_code'] : '—' }}</h2>
                <p data-donations-breakdown data-template="{{ __('module-donations::messages.player_price_breakdown') }}">{{ $quote !== null ? __('module-donations::messages.player_price_breakdown', [
                    'coins' => $quote['coins'],
                    'price' => $money->format($quote['unit_price_minor'], $quote['exponent']),
                    'currency' => $quote['currency_code'],
                ]) : '' }}</p>
            </div>
        </div>
    @endif
    @unless($liveCheckoutEnabled)
        <p class="account-field-help">{{ __('module-donations::messages.yoomoney_temporarily_unavailable') }}</p>
    @endunless
    @if($currencies !== [] && $liveCheckoutEnabled)
        <form method="POST" action="{{ route('modules.donations.orders.store') }}" data-testid="donations-create-order">
            @csrf
            <input type="hidden" name="coins" value="{{ $quote['coins'] ?? '' }}">
            <input type="hidden" name="currency" value="{{ $quote['currency_code'] ?? '' }}">
            <input type="hidden" name="request_key" value="{{ $draftRequestKey }}">
            @error('order')<p class="account-field-error" role="alert">{{ $message }}</p>@enderror
            <button class="account-button secondary" type="submit" @disabled($quote === null || $quoteError !== null)>{{ __('module-donations::messages.yoomoney_top_up') }}</button>
        </form>
    @endif
</x-account-panel>
<x-account-panel layout="row" class="account-wallet-summary account-section" data-testid="donations-account-wallet">
    <span class="account-wallet-summary-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
    <div class="account-wallet-summary-text">
        <span class="account-eyebrow">{{ __('module-donations::messages.current_balance_label') }}</span>
        <h2>{{ $walletView['label'] }}</h2>
    </div>
    <a wire:navigate class="account-button secondary" href="{{ public_route('wallet.index') }}">{{ __('module-donations::messages.go_to_wallet') }}</a>
</x-account-panel>
</x-account-page>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/donations-player.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
