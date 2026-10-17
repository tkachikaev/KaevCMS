@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.admin_title'))
@section('description', __('module-donations::messages.admin_description'))
@section('content')
@php
    $readOnly = (bool) request()->attributes->get('admin_read_only');
    $rows = [];
    foreach ($rates as $rate) {
        $rows[$rate->currency_code] = [
            'unit_price' => $money->format((int) $rate->unit_price_minor, (int) $rate->exponent),
            'enabled' => (bool) $rate->enabled, 'remove' => false, 'existing' => true,
        ];
    }
    $pendingRates = old('rates');
    if (is_array($pendingRates)) {
        foreach ($pendingRates as $code => $pending) {
            if (is_string($code) && is_array($pending) && $registry->find($code)) {
                $rows[$code] = array_merge($rows[$code] ?? ['unit_price' => '', 'enabled' => false, 'remove' => false, 'existing' => false], array_intersect_key($pending, array_flip(['unit_price', 'enabled', 'remove'])));
            }
        }
    }
@endphp
@include('module-donations::admin.navigation')
<div class="donations-mode" role="note">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3v1"/></svg>
    <div><strong>{{ \KaevCMS\Modules\Donations\Services\DonationYooMoneyCheckout::enabled() ? __('module-donations::messages.live_mode_title') : __('module-donations::messages.test_mode_title') }}</strong><p>{{ \KaevCMS\Modules\Donations\Services\DonationYooMoneyCheckout::enabled() ? __('module-donations::messages.live_mode_description') : __('module-donations::messages.test_mode_description') }}</p></div>
</div>
<form method="POST" action="{{ route('admin.module-pages.donations.workspace.update', ['adminPath' => $adminPath]) }}" data-donations-workspace data-testid="donations-workspace">
    @csrf @method('PUT')
    <input type="hidden" name="snapshot" value="{{ old('snapshot', $snapshot) }}">
    <div class="donations-layout">
        <x-admin.card data-testid="donations-currency-rates">
            <x-admin.card-heading :title="__('module-donations::messages.rates_title')" :description="__('module-donations::messages.workspace_help')" />
            @unless($readOnly)
                <div class="donations-add-currency" data-testid="donations-add-currency">
                    <x-admin.field for="donations-currency-code" :label="__('module-donations::messages.add_currency')">
                        <select id="donations-currency-code" data-donations-currency>
                            <option value="">{{ __('module-donations::messages.choose_currency') }}</option>
                            @foreach($currencies as $currency)
                                <option value="{{ $currency['code'] }}" data-name="{{ $currency['name'] }}" data-exponent="{{ $currency['exponent'] }}" @disabled(array_key_exists($currency['code'], $rows))>{{ $currency['code'] }} · {{ $currency['name'] }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.button type="button" data-donations-add>{{ __('module-donations::messages.add_currency') }}</x-admin.button>
                </div>
            @endunless
            <div class="donations-rate-list" data-donations-rates>
                @foreach($rows as $code => $row)
                    @php($currency = $registry->find($code, app()->getLocale()))
                    @if($currency)
                        @include('module-donations::admin.rate-row', ['code' => $code, 'row' => $row, 'currency' => $currency])
                    @endif
                @endforeach
            </div>
            <div class="admin-empty-state" data-donations-empty @if(collect($rows)->contains(fn ($row) => ! (bool) $row['remove'])) hidden @endif>{{ __('module-donations::messages.no_rates') }}</div>
            <div class="donations-preview">
                <x-admin.field for="donations-preview-coins" :label="__('module-donations::messages.preview_coins')">
                    <input id="donations-preview-coins" data-donations-preview type="number" min="1" max="1000000" value="{{ $previewCoins }}">
                </x-admin.field>
                <p>{{ __('module-donations::messages.preview_live') }}</p>
            </div>
        </x-admin.card>
        <aside class="donations-side">
            <x-admin.card>
                <x-admin.card-heading :title="__('module-donations::messages.general_settings')" />
                <x-admin.toggle id="donations-show-in-account" name="show_in_account" :label="__('module-donations::messages.show_in_account')" :hint="__('module-donations::messages.show_in_account_help')" :checked="(bool) old('show_in_account', $showInAccount)" :disabled="$readOnly" />
            </x-admin.card>
            <x-admin.card data-testid="donations-coin-limits">
                <x-admin.card-heading :title="__('module-donations::messages.limits_title')" :description="__('module-donations::messages.limits_description')" />
                <div class="settings-grid">
                    <x-admin.field for="donations-min-coins" name="min_coins" :label="__('module-donations::messages.min_coins')">
                        <input id="donations-min-coins" type="number" min="1" max="1000000" name="min_coins" required value="{{ old('min_coins', $limits['min_coins']) }}" @disabled($readOnly)>
                    </x-admin.field>
                    <x-admin.field for="donations-max-coins" name="max_coins" :label="__('module-donations::messages.max_coins')">
                        <input id="donations-max-coins" type="number" min="1" max="1000000" name="max_coins" required value="{{ old('max_coins', $limits['max_coins']) }}" @disabled($readOnly)>
                    </x-admin.field>
                </div>
            </x-admin.card>
        </aside>
    </div>
    @unless($readOnly)
        <div class="donations-save-bar">
            <p data-donations-save-status data-saved="{{ __('module-donations::messages.save_help') }}" data-dirty="{{ __('module-donations::messages.unsaved_changes') }}" role="status">{{ __('module-donations::messages.save_help') }}</p>
            <x-admin.button type="submit" variant="primary">{{ __('module-donations::messages.save_all') }}</x-admin.button>
        </div>
        <template data-donations-rate-template>
            @include('module-donations::admin.rate-row', ['code' => '__CODE__', 'currency' => ['name' => '', 'exponent' => 2], 'row' => ['unit_price' => '', 'enabled' => true, 'remove' => false, 'existing' => false]])
        </template>
    @endunless
</form>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/donations-admin.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush