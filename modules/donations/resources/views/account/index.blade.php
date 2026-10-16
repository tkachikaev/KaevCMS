@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.account_title'))
@section('content')
<section class="account-page-heading">
    <div>
        <span class="account-eyebrow">{{ __('module-donations::messages.navigation_label') }}</span>
        <h1>{{ __('module-donations::messages.account_title') }}</h1>
        <p>{{ __('module-donations::messages.not_available_yet') }}</p>
    </div>
    <a wire:navigate class="account-button secondary" href="{{ route('modules.donations.orders.index') }}">{{ __('module-donations::messages.orders_title') }}</a>
</section>
<section class="account-section" data-testid="donations-player-calculator">
    <div class="account-section-heading">
        <div>
            <span class="account-eyebrow">{{ __('module-donations::messages.rates_title') }}</span>
            <h2>{{ __('module-donations::messages.choose_coins') }}</h2>
            <p>{{ __('module-donations::messages.player_calculator_help') }}</p>
        </div>
    </div>
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
                                <option value="{{ $currency['code'] }}" @selected($currency['code'] === $selectedCurrency)>{{ $currency['code'] }} — {{ $currency['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </label>
            </div>
            <p>{{ __('module-donations::messages.player_coin_limits', ['min' => $limits['min_coins'], 'max' => $limits['max_coins']]) }}</p>
            <div class="account-form-actions">
                <button class="account-button primary" type="submit">{{ __('module-donations::messages.player_calculate') }}</button>
            </div>
        </form>
        @if($quoteError !== null)
            <p class="account-field-error" role="alert" data-testid="donations-quote-error">{{ $quoteError }}</p>
        @elseif($quote !== null)
            <div class="account-wallet-summary" data-testid="donations-quote-result" aria-live="polite">
                <div class="account-wallet-summary-text">
                    <span class="account-eyebrow">{{ __('module-donations::messages.player_estimated_total') }}</span>
                    <h2>{{ $money->format($quote['total_minor'], $quote['exponent']) }} {{ $quote['currency_code'] }}</h2>
                    <p>{{ __('module-donations::messages.player_price_breakdown', [
                        'coins' => $quote['coins'],
                        'price' => $money->format($quote['unit_price_minor'], $quote['exponent']),
                        'currency' => $quote['currency_code'],
                    ]) }}</p>
                </div>
            </div>
        @endif
    @endif
    <p>{{ __('module-donations::messages.player_preview_only') }}</p>
    <p>{{ __('module-donations::messages.dry_run_warning') }}</p>
    @if($quote !== null && $quoteError === null)
        <form method="POST" action="{{ route('modules.donations.orders.store') }}" data-testid="donations-create-test-draft">
            @csrf
            <input type="hidden" name="coins" value="{{ $quote['coins'] }}">
            <input type="hidden" name="currency" value="{{ $quote['currency_code'] }}">
            <input type="hidden" name="request_key" value="{{ $draftRequestKey }}">
            @error('order')<p class="account-field-error" role="alert">{{ $message }}</p>@enderror
            <button class="account-button secondary" type="submit">{{ __('module-donations::messages.create_test_draft') }}</button>
        </form>
    @endif
</section>
<section class="account-wallet-summary account-section" data-testid="donations-account-wallet">
    <span class="account-wallet-summary-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
    <div class="account-wallet-summary-text">
        <span class="account-eyebrow">{{ __('module-donations::messages.current_balance_label') }}</span>
        <h2>{{ $walletView['label'] }}</h2>
    </div>
    <a wire:navigate class="account-button secondary" href="{{ public_route('wallet.index') }}">{{ __('module-donations::messages.go_to_wallet') }}</a>
</section>
@endsection
