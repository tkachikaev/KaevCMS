@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.providers_title'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-providers">
    <h2>{{ __('module-donations::messages.providers_title') }}</h2>
    <p class="admin-field-help">{{ __('module-donations::messages.providers_intro') }}</p>
    @forelse($providers as $provider)
        <div class="settings-form">
            <h3>{{ $provider['name'] }}</h3>
            <p>{{ $provider['code'] }} · {{ $provider['enabled'] ? __('module-donations::messages.provider_on') : __('module-donations::messages.provider_off') }} · {{ $provider['mode'] === 'test' ? __('module-donations::messages.provider_test') : __('module-donations::messages.provider_live') }}</p>
            <p class="admin-field-help">{{ implode(', ', $provider['currency_codes']) }}</p>
            <a class="button button-secondary" wire:navigate href="{{ route('admin.module-pages.donations.providers.show', ['adminPath' => $adminPath, 'providerCode' => $provider['code']]) }}">{{ __('module-donations::messages.provider_configure') }}</a>
        </div>
    @empty
        <div class="donations-provider-empty">
            <strong>{{ __('module-donations::messages.providers_none_installed') }}</strong>
            <p>{{ __('module-donations::messages.providers_empty_help') }}</p>
            <a class="button button-secondary" wire:navigate href="{{ route('admin.module-pages.donations.index', ['adminPath' => $adminPath]) }}">{{ __('module-donations::messages.rates_title') }}</a>
        </div>
    @endforelse
</section>
@endsection
