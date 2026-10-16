@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.admin_title'))
@section('description', __('module-donations::messages.admin_description'))
@section('content')
@php($readOnly = (bool) request()->attributes->get('admin_read_only'))
@php($configuredCodes = array_map(static fn ($rate) => $rate->currency_code, $rates))
@include('module-donations::admin.navigation')
<div class="donations-mode" role="note">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3v1"/></svg>
    <div><strong>{{ __('module-donations::messages.test_mode_title') }}</strong><p>{{ __('module-donations::messages.test_mode_description') }}</p></div>
</div>
<div class="donations-layout">
    <x-admin.card data-testid="donations-currency-rates">
        <x-admin.card-heading :title="__('module-donations::messages.rates_title')" :description="__('module-donations::messages.rates_description')" />
        <div class="donations-rate-list">
            @forelse($rates as $rate)
                @php($currency = $registry->find($rate->currency_code, app()->getLocale()))
                @if($currency)
                    <form method="POST" action="{{ route('admin.module-pages.donations.rates.store', ['adminPath' => $adminPath]) }}" class="donations-rate-form" data-testid="donations-rate-{{ $rate->currency_code }}">
                        @csrf
                        <input type="hidden" name="currency_code" value="{{ $rate->currency_code }}">
                        <div class="donations-currency-identity">
                            <span class="donations-currency-code">{{ $rate->currency_code }}</span>
                            <div><strong>{{ $currency['name'] }}</strong><small>{{ __('module-donations::messages.price_per_coin') }}</small></div>
                        </div>
                        <x-admin.field :for="'donations-price-'.$rate->currency_code" name="unit_price" :label="__('module-donations::messages.price_label', ['code' => $rate->currency_code])">
                            <input id="donations-price-{{ $rate->currency_code }}" name="unit_price" type="text" inputmode="decimal" required value="{{ old('currency_code') === $rate->currency_code ? old('unit_price', $money->format((int) $rate->unit_price_minor, (int) $rate->exponent)) : $money->format((int) $rate->unit_price_minor, (int) $rate->exponent) }}" @disabled($readOnly)>
                        </x-admin.field>
                        @unless($readOnly)<x-admin.button type="submit" variant="secondary">{{ __('module-donations::messages.save_rate') }}</x-admin.button>@endunless
                        <div class="donations-rate-footer">
                            <x-admin.toggle :id="'donations-enabled-'.$rate->currency_code" name="enabled" :label="__('module-donations::messages.enable_rate')" :checked="(bool) $rate->enabled" :disabled="$readOnly" compact />
                            <p>{{ __('module-donations::messages.rate_preview', ['count' => $previewCoins, 'amount' => $money->format($money->totalMinor((int) $rate->unit_price_minor, $previewCoins), (int) $rate->exponent), 'code' => $rate->currency_code]) }}</p>
                        </div>
                    </form>
                @endif
            @empty
                <div class="admin-empty-state">{{ __('module-donations::messages.no_rates') }}</div>
            @endforelse
        </div>
        @unless($readOnly)
            <details class="donations-add-currency" @if($rates === [] || ($errors->any() && ! in_array(old('currency_code'), $configuredCodes, true))) open @endif>
                <summary>{{ __('module-donations::messages.add_currency') }}</summary>
                <form method="POST" action="{{ route('admin.module-pages.donations.rates.store', ['adminPath' => $adminPath]) }}" data-testid="donations-add-currency">
                    @csrf
                    <div class="settings-grid">
                        <x-admin.field for="donations-currency-code" name="currency_code" :label="__('module-donations::messages.currency')">
                            <select name="currency_code" id="donations-currency-code" required>
                                <option value="" disabled @selected(! old('currency_code'))>{{ __('module-donations::messages.choose_currency') }}</option>
                                @foreach($currencies as $currency)
                                    @unless(in_array($currency['code'], $configuredCodes, true))
                                        <option value="{{ $currency['code'] }}" @selected(old('currency_code') === $currency['code'])>{{ $currency['code'] }} · {{ $currency['name'] }}</option>
                                    @endunless
                                @endforeach
                            </select>
                        </x-admin.field>
                        <x-admin.field for="donations-new-unit-price" name="unit_price" :label="__('module-donations::messages.price_per_coin')" :hint="__('module-donations::messages.price_hint')">
                            <input id="donations-new-unit-price" name="unit_price" type="text" inputmode="decimal" required value="{{ old('unit_price') }}" placeholder="1.00">
                        </x-admin.field>
                    </div>
                    <x-admin.toggle id="donations-new-enabled" name="enabled" :label="__('module-donations::messages.enable_rate')" :checked="(bool) old('enabled', false)" compact />
                    <div class="donations-form-actions"><x-admin.button type="submit" variant="primary">{{ __('module-donations::messages.add_currency') }}</x-admin.button></div>
                </form>
            </details>
        @endunless
        @if($rates !== [])
            <form method="GET" action="{{ route('admin.module-pages.donations.index', ['adminPath' => $adminPath]) }}" class="donations-preview">
                <x-admin.field for="donations-preview-coins" name="preview_coins" :label="__('module-donations::messages.preview_coins')">
                    <input id="donations-preview-coins" name="preview_coins" type="number" min="{{ $limits['min_coins'] }}" max="{{ $limits['max_coins'] }}" value="{{ $previewCoins }}" required>
                </x-admin.field>
                <x-admin.button type="submit">{{ __('module-donations::messages.preview_label') }}</x-admin.button>
            </form>
        @endif
    </x-admin.card>
    <aside class="donations-side">
        <x-admin.card>
            <x-admin.card-heading :title="__('module-donations::messages.general_settings')" />
            <form method="POST" action="{{ route('admin.module-pages.donations.settings.update', ['adminPath' => $adminPath]) }}">
                @csrf @method('PUT')
                <x-admin.toggle id="donations-show-in-account" name="show_in_account" :label="__('module-donations::messages.show_in_account')" :hint="__('module-donations::messages.show_in_account_help')" :checked="(bool) old('show_in_account', $showInAccount)" :disabled="$readOnly" />
                @unless($readOnly)<x-admin.button type="submit" variant="primary">{{ __('module-donations::messages.save_settings') }}</x-admin.button>@endunless
            </form>
        </x-admin.card>
        <x-admin.card data-testid="donations-coin-limits">
            <x-admin.card-heading :title="__('module-donations::messages.limits_title')" :description="__('module-donations::messages.limits_description')" />
            <form method="POST" action="{{ route('admin.module-pages.donations.limits.update', ['adminPath' => $adminPath]) }}">
                @csrf @method('PUT')
                <div class="settings-grid">
                    <x-admin.field for="donations-min-coins" name="min_coins" :label="__('module-donations::messages.min_coins')">
                        <input id="donations-min-coins" type="number" min="1" max="1000000" name="min_coins" required value="{{ old('min_coins', $limits['min_coins']) }}" @disabled($readOnly)>
                    </x-admin.field>
                    <x-admin.field for="donations-max-coins" name="max_coins" :label="__('module-donations::messages.max_coins')">
                        <input id="donations-max-coins" type="number" min="1" max="1000000" name="max_coins" required value="{{ old('max_coins', $limits['max_coins']) }}" @disabled($readOnly)>
                    </x-admin.field>
                </div>
                @unless($readOnly)<div class="donations-form-actions"><x-admin.button type="submit" variant="secondary">{{ __('module-donations::messages.save_limits') }}</x-admin.button></div>@endunless
            </form>
        </x-admin.card>
    </aside>
</div>
@endsection
