@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.admin_title'))
@section('description', __('module-donations::messages.admin_description'))
@section('content')
<div class="content-toolbar"><a class="button button-secondary" wire:navigate href="{{ route('admin.module-pages.donations.providers.index', ['adminPath' => $adminPath]) }}">{{ __('module-donations::messages.providers_title') }}</a></div>
<div class="content-toolbar">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.donations.orders.index', ['adminPath' => $adminPath]) }}">{{ __('module-donations::messages.orders_admin_title') }}</a>
    <a wire:navigate class="button button-secondary" href="{{ route('admin.modules.index', ['adminPath' => $adminPath]) }}">← {{ __('module-donations::messages.back_to_modules') }}</a>
</div>
<x-admin.card narrow>
    <x-admin.card-heading
        :title="__('module-donations::messages.general_settings')"
        :description="__('module-donations::messages.general_settings_description')"
    />
    <p>{{ __('module-donations::messages.not_accepting_payments') }}</p>
    <p>{{ __('module-donations::messages.test_drafts_info') }}</p>
    <p>{{ __('module-donations::messages.wallet_independent') }}</p>
    <form method="POST" action="{{ route('admin.module-pages.donations.settings.update', ['adminPath' => $adminPath]) }}">
        @csrf
        @method('PUT')
        <x-admin.toggle
            id="donations-show-in-account"
            name="show_in_account"
            :label="__('module-donations::messages.show_in_account')"
            :hint="__('module-donations::messages.show_in_account_help')"
            :checked="(bool) old('show_in_account', $showInAccount)"
            :disabled="(bool) (request()->attributes->get('admin_read_only') ?? false)"
        />
        @unless(request()->attributes->get('admin_read_only'))
            <x-admin.button type="submit" variant="primary">{{ __('module-donations::messages.save_settings') }}</x-admin.button>
        @endunless
    </form>
</x-admin.card>

<section class="form-card" data-testid="donations-coin-limits">
    <h2>{{ __('module-donations::messages.limits_title') }}</h2>
    <p>{{ __('module-donations::messages.limits_description') }}</p>
    <form method="POST" action="{{ route('admin.module-pages.donations.limits.update', ['adminPath' => $adminPath]) }}" class="settings-form">
        @csrf @method('PUT')
        <div class="settings-grid">
            <div class="form-group"><label for="donations-min-coins">{{ __('module-donations::messages.min_coins') }}</label>
                <input id="donations-min-coins" type="number" min="1" max="1000000" name="min_coins" required value="{{ old('min_coins', $limits['min_coins']) }}" @disabled(request()->attributes->get('admin_read_only'))>
                @error('min_coins')<small class="field-error">{{ $message }}</small>@enderror</div>
            <div class="form-group"><label for="donations-max-coins">{{ __('module-donations::messages.max_coins') }}</label>
                <input id="donations-max-coins" type="number" min="1" max="1000000" name="max_coins" required value="{{ old('max_coins', $limits['max_coins']) }}" @disabled(request()->attributes->get('admin_read_only'))>
                @error('max_coins')<small class="field-error">{{ $message }}</small>@enderror</div>
        </div>
        @unless(request()->attributes->get('admin_read_only'))<button class="button button-primary" type="submit">{{ __('module-donations::messages.save_limits') }}</button>@endunless
    </form>
</section>
<section class="form-card" data-testid="donations-currency-rates">
    <h2>{{ __('module-donations::messages.rates_title') }}</h2>
    <p>{{ __('module-donations::messages.rates_description') }}</p>
    <p>{{ __('module-donations::messages.payment_disabled_b2') }}</p>
    <form method="GET" action="{{ route('admin.module-pages.donations.index', ['adminPath' => $adminPath]) }}" class="settings-form">
        <div class="settings-grid">
            <div class="form-group"><label for="donations-preview-coins">{{ __('module-donations::messages.preview_coins') }}</label>
                <input id="donations-preview-coins" name="preview_coins" type="number" min="{{ $limits['min_coins'] }}" max="{{ $limits['max_coins'] }}" value="{{ $previewCoins }}" required></div>
        </div>
        <button type="submit" class="button button-secondary">{{ __('module-donations::messages.preview_label') }}</button>
    </form>
    @if($rates === [])<div class="admin-empty-state">{{ __('module-donations::messages.no_rates') }}</div>@endif
    @foreach($rates as $rate)
        @php($currency = $registry->find($rate->currency_code, app()->getLocale()))
        @if($currency)
        <form method="POST" action="{{ route('admin.module-pages.donations.rates.store', ['adminPath' => $adminPath]) }}" class="settings-form" data-testid="donations-rate-{{ $rate->currency_code }}">
            @csrf
            <input type="hidden" name="currency_code" value="{{ $rate->currency_code }}">
            <div class="settings-grid">
                <div class="form-group"><label>{{ __('module-donations::messages.currency') }}</label><div><strong>{{ $rate->currency_code }}</strong> · {{ $currency['name'] }} ({{ $currency['symbol'] }})</div><small>{{ __('module-donations::messages.minor_digits', ['count' => $currency['exponent']]) }}</small></div>
                <div class="form-group"><label for="donations-price-{{ $rate->currency_code }}">{{ __('module-donations::messages.price_per_coin') }}</label>
                    <input id="donations-price-{{ $rate->currency_code }}" name="unit_price" type="text" inputmode="decimal" required value="{{ $money->format((int) $rate->unit_price_minor, (int) $rate->exponent) }}" @disabled(request()->attributes->get('admin_read_only'))></div>
            </div>
            <input type="hidden" name="enabled" value="0">
            <label class="settings-remove-row"><input type="checkbox" name="enabled" value="1" @checked($rate->enabled) @disabled(request()->attributes->get('admin_read_only'))> {{ __('module-donations::messages.enable_rate') }}</label>
            @if($rate->enabled)
                <p class="admin-field-help">{{ __('module-donations::messages.rate_preview', ['count' => $previewCoins, 'amount' => $money->format($money->totalMinor((int) $rate->unit_price_minor, $previewCoins), (int) $rate->exponent), 'code' => $rate->currency_code]) }}</p>
            @endif
            @unless(request()->attributes->get('admin_read_only'))<button type="submit" class="button button-primary">{{ __('module-donations::messages.save_rate') }}</button>@endunless
        </form>
        @endif
    @endforeach
    @unless(request()->attributes->get('admin_read_only'))
        @php($configuredCodes = array_map(static fn ($rate) => $rate->currency_code, $rates))
        <h3>{{ __('module-donations::messages.add_currency') }}</h3>
        <form method="POST" action="{{ route('admin.module-pages.donations.rates.store', ['adminPath' => $adminPath]) }}" class="settings-form" data-testid="donations-add-currency">
            @csrf
            <div class="settings-grid">
                <div class="form-group"><label for="donations-currency-code">{{ __('module-donations::messages.currency') }}</label>
                    <select name="currency_code" id="donations-currency-code" required>
                        @foreach($currencies as $currency)
                            @unless(in_array($currency['code'], $configuredCodes, true))
                                <option value="{{ $currency['code'] }}" @selected(old('currency_code') === $currency['code'])>{{ $currency['code'] }} · {{ $currency['name'] }} ({{ $currency['symbol'] }})</option>
                            @endunless
                        @endforeach
                    </select>
                    @error('currency_code')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group"><label for="donations-new-unit-price">{{ __('module-donations::messages.price_per_coin') }}</label>
                    <input id="donations-new-unit-price" name="unit_price" type="text" inputmode="decimal" required value="{{ old('unit_price', '1') }}">
                    @error('unit_price')<small class="field-error">{{ $message }}</small>@enderror
                </div>
            </div>
            <p class="admin-field-help">{{ __('module-donations::messages.price_hint') }}</p>
            <input type="hidden" name="enabled" value="0">
            <label class="settings-remove-row"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', false))> {{ __('module-donations::messages.enable_rate') }}</label>
            <button type="submit" class="button button-primary">{{ __('module-donations::messages.add_currency') }}</button>
        </form>
    @endunless
</section>
@endsection
