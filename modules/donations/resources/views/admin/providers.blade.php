@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.providers_title'))
@section('description', __('module-donations::messages.providers_admin_description'))
@section('docs', 'donations-providers')
@section('content')
@include('module-donations::admin.navigation')
<div class="donations-provider-grid" data-testid="donations-providers">
    @forelse($providers as $provider)
        @include('module-donations::admin.provider-card', [
            'code' => $provider['code'],
            'name' => $provider['code'] === 'yoomoney' ? 'ЮMoney' : $provider['name'],
            'description' => match ($provider['code']) {
                'yoomoney' => __('module-donations::messages.provider_wallet_card'),
                'freekassa' => __('module-donations::messages.freekassa_provider_description'),
                default => null,
            },
            'accepting' => $provider['code'] === 'yoomoney' ? $yoomoneyAccepting : $provider['enabled'],
            'settingsUrl' => route('admin.module-pages.donations.providers.show', ['adminPath' => $adminPath, 'providerCode' => $provider['code']]),
        ])
    @empty
        <div class="donations-provider-empty">
            <strong>{{ __('module-donations::messages.providers_none_installed') }}</strong>
            <p>{{ __('module-donations::messages.providers_empty_help') }}</p>
            <a class="button button-secondary" wire:navigate href="{{ route('admin.module-pages.donations.index', ['adminPath' => $adminPath]) }}">{{ __('module-donations::messages.rates_title') }}</a>
        </div>
    @endforelse
    @php($paypalState = app(\KaevCMS\Modules\Donations\Services\PayPalAdminSettings::class)->state())
    @include('module-donations::admin.provider-card', [
        'code' => 'paypal',
        'name' => 'PayPal',
        'description' => __('module-donations::messages.paypal_provider_description'),
        'accepting' => $paypalState['enabled'],
        'settingsUrl' => route('admin.module-pages.donations.providers.paypal.show', ['adminPath' => $adminPath]),
    ])
</div>
@endsection
