@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.account_title'))
@section('description', __('module-donations::messages.account_description'))
@section('content')
<x-account-page>

@include('module-donations::account.calculator')
@if(app(\KaevCMS\Modules\Donations\Services\DonationGameExchange::class)->available() !== [])
    <x-account-panel class="account-section">
        <x-account-panel-header :title="__('module-donations::messages.exchange_title')" />
        <p>{{ __('module-donations::messages.exchange_account_help') }}</p>
        <div class="account-form-actions"><a class="account-button primary" href="{{ route('modules.donations.exchange.index') }}">{{ __('module-donations::messages.exchange_open') }} →</a></div>
    </x-account-panel>
@endif
<x-account-panel layout="row" class="account-wallet-summary account-section" data-testid="donations-account-wallet">
    <span class="account-wallet-summary-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
    <div class="account-wallet-summary-text">
        <span class="account-eyebrow">{{ __('module-donations::messages.current_balance_label') }}</span>
        <h2>{{ $walletView['label'] }}</h2>
    </div>
</x-account-panel>
</x-account-page>
@endsection
