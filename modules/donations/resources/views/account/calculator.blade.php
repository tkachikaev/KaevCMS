<x-account-panel class="account-section account-donations-card account-donations-calculator" data-testid="donations-player-calculator" data-donations-calculator>
    @unless($isDialog ?? false)
        <x-account-panel-header :title="__('module-donations::messages.topup_title')">
            <x-slot:actions><a wire:navigate class="account-button secondary" href="{{ public_route('wallet.index') }}">{{ __('History') }}</a></x-slot:actions>
        </x-account-panel-header>
    @endunless
    @if($currencies === [])
        <p data-testid="donations-no-currencies">{{ __('module-donations::messages.player_no_currencies') }}</p>
    @else
        <div class="donations-topup-grid">
            <div class="donations-topup-controls">
                <form method="GET" action="{{ route('modules.donations.index') }}" class="account-form-card" data-testid="donations-quote-form">
                    <label for="donations-player-coins"><span>{{ __('module-donations::messages.player_coin_quantity') }}</span>
                        <div class="account-field-control donations-coins-control"><input id="donations-player-coins" type="number" inputmode="numeric" min="{{ $limits['min_coins'] }}" max="{{ $limits['max_coins'] }}" step="1" name="coins" value="{{ $coinsInput }}" required><span aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span></div>
                    </label>
                    <div class="donations-quantity-range" data-donations-range-control hidden>
                        <label for="donations-player-range"><span>{{ __('module-donations::messages.player_quantity_slider') }}</span></label>
                        <input id="donations-player-range" type="range" min="{{ $limits['min_coins'] }}" max="{{ $limits['max_coins'] }}" step="1" value="{{ $coinsInput ?: $limits['min_coins'] }}" aria-describedby="donations-player-limits">
                        <div class="donations-range-labels" aria-hidden="true"><span>{{ number_format($limits['min_coins'], 0, '.', ' ') }}</span><span>{{ number_format($limits['max_coins'], 0, '.', ' ') }}</span></div>
                    </div>
                    <p id="donations-player-limits" class="account-field-help">{{ __('module-donations::messages.player_coin_limits', ['min' => $limits['min_coins'], 'max' => $limits['max_coins']]) }}</p>
                    <label for="donations-player-currency"><span>{{ __('module-donations::messages.currency') }}</span>
                        <div class="account-field-control"><select id="donations-player-currency" name="currency" required>
                            @foreach($currencies as $currency)
                                <option value="{{ $currency['code'] }}" data-price-minor="{{ $currency['unit_price_minor'] }}" data-exponent="{{ $currency['exponent'] }}" @selected($currency['code'] === $selectedCurrency)>{{ $currency['code'] }} — {{ $currency['name'] }}</option>
                            @endforeach
                        </select></div>
                    </label>
                    <p class="account-field-help" data-donations-live-help hidden>{{ __('module-donations::messages.player_live_total') }}</p>
                    <div class="account-form-actions" data-donations-calculate-fallback><button class="account-button secondary" type="submit">{{ __('module-donations::messages.player_calculate') }}</button></div>
                </form>
                <p class="account-field-error" role="alert" data-testid="donations-quote-error" data-invalid-coins="{{ __('module-donations::messages.player_invalid_coins') }}" data-invalid-currency="{{ __('module-donations::messages.player_invalid_currency') }}" @if($quoteError === null) hidden @endif>{{ $quoteError }}</p>
            </div>
            <div class="donations-topup-payment">
                <div class="donations-topup-total" data-testid="donations-quote-result" aria-live="polite" aria-atomic="true" @if($quote === null) hidden @endif>
                    <span class="account-eyebrow">{{ __('module-donations::messages.topup_receive') }}</span>
                    <strong class="donations-topup-receive" data-donations-receive data-one="{{ $coinForms['one'] }}" data-few="{{ $coinForms['few'] }}" data-many="{{ $coinForms['many'] }}">{{ $coinsLabel }}</strong>
                    <div class="donations-topup-cost"><span>{{ __('module-donations::messages.topup_due') }}</span><strong data-donations-total>{{ $quote !== null ? $money->format($quote['total_minor'], $quote['exponent']).' '.$quote['currency_code'] : '—' }}</strong></div>
                    <p class="account-field-help" data-donations-breakdown data-template="{{ __('module-donations::messages.player_price_breakdown') }}">{{ $quote !== null ? __('module-donations::messages.player_price_breakdown', ['coins' => $quote['coins'], 'price' => $money->format($quote['unit_price_minor'], $quote['exponent']), 'currency' => $quote['currency_code']]) : '' }}</p>
                </div>
                @if($liveCheckoutEnabled)
                    <form method="POST" action="{{ route('modules.donations.orders.store') }}" data-topup-url="{{ route('modules.donations.topup') }}" data-testid="donations-create-order" data-failed="{{ __('module-donations::messages.topup_failed') }}" data-pending="{{ __('module-donations::messages.topup_pending') }}">
                        @csrf
                        <input type="hidden" name="coins" value="{{ $quote['coins'] ?? '' }}">
                        <input type="hidden" name="currency" value="{{ $quote['currency_code'] ?? '' }}">
                        <input type="hidden" name="request_key" value="{{ $draftRequestKey }}">
                        <fieldset class="donations-providers"><legend>{{ __('module-donations::messages.topup_provider') }}</legend>
                            @foreach($paymentProviders as $provider)
                                <label class="donations-provider" data-donations-provider data-currencies="{{ implode(',', $provider['currencies']) }}">
                                    <input type="radio" name="provider" value="{{ $provider['code'] }}" @checked($loop->first) required>
                                    <span class="donations-provider-card">
                                        @php($providerLogo = ['yoomoney' => 'yoomoney.svg', 'freekassa' => 'freekassa.png', 'paypal' => 'paypal.svg'][$provider['code']] ?? null)
                                        @if($providerLogo !== null)<span class="donations-provider-logo"><img src="{{ asset('assets/modules/'.$providerLogo) }}" alt="" width="100" height="24"></span>@endif
                                        <span><strong>{{ $provider['name'] }}</strong><small>{{ match ($provider['code']) {
                                            'freekassa' => __('module-donations::messages.freekassa_provider_description'),
                                            'paypal' => __('module-donations::messages.paypal_provider_description'),
                                            default => __('module-donations::messages.topup_provider_hint', ['provider' => $provider['name']]),
                                        } }}</small></span><span class="donations-provider-check" aria-hidden="true">✓</span>
                                    </span>
                                </label>
                            @endforeach
                        </fieldset>
                        <p class="account-field-error" role="alert" data-donations-submit-error hidden></p>
                        @error('order')<p class="account-field-error" role="alert">{{ $message }}</p>@enderror
                        <button class="account-button primary donations-topup-submit" type="submit" @disabled($quote === null || $quoteError !== null)>{{ __('module-donations::messages.topup_submit') }}</button>
                        <p class="account-field-help donations-topup-caption">{{ __('module-donations::messages.topup_redirect') }}</p>
                    </form>
                @else
                    <p class="account-field-help">{{ __('module-donations::messages.yoomoney_temporarily_unavailable') }}</p>
                @endif
            </div>
        </div>
    @endif
    @if($isDialog ?? false)
        <nav class="donations-history-actions" aria-label="{{ __('History') }}">
            <a wire:navigate class="account-button secondary" href="{{ public_route('wallet.index') }}">{{ __('History') }}</a>
        </nav>
    @endif
</x-account-panel>
