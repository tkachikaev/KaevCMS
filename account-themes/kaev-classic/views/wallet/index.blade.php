@extends('account-theme::layouts.app')
@section('title', __('My wallet'))
@section('description', __('Your virtual currency balance is shared across all game accounts on this site.'))
@section('content')
<x-account-page>

<x-account-panel layout="row" class="account-wallet-summary account-section" aria-label="{{ __('Wallet balance') }}">
    <span class="account-wallet-summary-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
    <div class="account-wallet-summary-text"><span class="account-eyebrow">{{ __('Available balance') }}</span><h2 data-wallet-balance-label>{{ $walletView['label'] }}</h2></div>
    @if($walletView['blocked'])
        <span class="account-wallet-blocked" role="status">{{ __('Wallet spending suspended') }}</span>
    @endif
    <div class="account-wallet-actions">
    @if($topUpUrl !== null)
        <a wire:navigate class="account-button primary account-wallet-topup" href="{{ $topUpUrl }}">{{ __('Top up balance') }}</a>
    @endif
    @foreach($walletLinks as $walletLink)
        <a wire:navigate class="account-button secondary" data-wallet-exchange href="{{ route($walletLink['route']) }}">{{ __($walletLink['label_key']) }}</a>
    @endforeach
    </div>
</x-account-panel>
@if($walletView['blocked'])
    <p class="account-inline-warning">{{ __('Spending from this wallet is disabled. Contact support for details.') }}</p>
@endif

<x-account-wallet-history :entries="$entries" :include-inactive="$includeInactive" :can-show-inactive="$canShowInactive" :coin-icon-url="$walletView['icon_url']" />
{{-- Players never see module names: without top-ups the page simply has no top-up button. --}}
</x-account-page>
@endsection
