@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.providers_title'))
@section('content')
@include('module-donations::admin.navigation')
<div class="donations-provider-grid" data-testid="donations-providers">
    @forelse($providers as $provider)
        <x-admin.card class="donations-provider-card" data-provider="{{ $provider['code'] }}">
            <div class="donations-provider-heading">
                <span class="donations-provider-logo" aria-hidden="true">
                    @if($provider['code'] === 'yoomoney')
                        <img src="{{ asset('assets/modules/yoomoney.svg') }}" width="40" height="40" alt="">
                    @else
                        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h4"/></svg>
                    @endif
                </span>
                <div><h2>{{ $provider['code'] === 'yoomoney' ? 'ЮMoney' : $provider['name'] }}</h2><small>{{ $provider['code'] }}</small></div>
            </div>
            @if($provider['code'] === 'yoomoney')<p class="donations-provider-description">{{ __('module-donations::messages.provider_wallet_card') }}</p>@endif
            <div class="donations-provider-badges">
                <span @class(['status-badge', 'status-badge-success' => $provider['enabled']])>{{ $provider['enabled'] ? __('module-donations::messages.provider_on') : __('module-donations::messages.provider_off') }}</span>
                <span class="status-badge">{{ $provider['mode'] === 'test' ? __('module-donations::messages.provider_test') : __('module-donations::messages.provider_live') }}</span>
            </div>
            <div class="donations-provider-currencies"><span>{{ __('module-donations::messages.provider_supported_currencies') }}</span><strong>{{ implode(', ', $provider['currency_codes']) ?: '—' }}</strong></div>
            <a class="button button-secondary" wire:navigate href="{{ route('admin.module-pages.donations.providers.show', ['adminPath' => $adminPath, 'providerCode' => $provider['code']]) }}">{{ __('module-donations::messages.provider_configure') }}</a>
        </x-admin.card>
    @empty
        <div class="donations-provider-empty">
            <strong>{{ __('module-donations::messages.providers_none_installed') }}</strong>
            <p>{{ __('module-donations::messages.providers_empty_help') }}</p>
            <a class="button button-secondary" wire:navigate href="{{ route('admin.module-pages.donations.index', ['adminPath' => $adminPath]) }}">{{ __('module-donations::messages.rates_title') }}</a>
        </div>
    @endforelse
</div>
@endsection
