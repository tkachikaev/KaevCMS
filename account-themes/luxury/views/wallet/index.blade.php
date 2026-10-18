@extends('account-theme::layouts.app')
@section('title', __('My wallet'))
@section('description', __('Your virtual currency balance is shared across all game accounts on this site.'))
@section('content')
<x-account-page>

<x-account-panel layout="row" class="account-wallet-summary account-section" aria-label="{{ __('Wallet balance') }}">
    <span class="account-wallet-summary-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
    <div class="account-wallet-summary-text"><span class="account-eyebrow">{{ __('Available balance') }}</span><h2>{{ $walletView['label'] }}</h2></div>
    @if($walletView['blocked'])
        <span class="account-wallet-blocked" role="status">{{ __('Wallet spending suspended') }}</span>
    @endif
    @if($topUpUrl !== null)
        <a wire:navigate class="account-button primary account-wallet-topup" href="{{ $topUpUrl }}">{{ __('Top up balance') }}</a>
    @endif
</x-account-panel>
@if($walletView['blocked'])
    <p class="account-inline-warning">{{ __('Spending from this wallet is disabled. Contact support for details.') }}</p>
@endif

<x-account-wallet-history :entries="$entries" :include-inactive="$includeInactive" :can-show-inactive="$canShowInactive" />
@if($topUpUrl === null)
<p class="account-wallet-payments-unavailable">{{ __('Online wallet top-ups will become available when Donations is enabled.') }}</p>
@endif
</x-account-page>
@endsection
