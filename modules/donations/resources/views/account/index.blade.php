@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.account_title'))
@section('description', __('module-donations::messages.account_description'))
@section('content')
<x-account-page>

@include('module-donations::account.calculator')
<x-account-panel layout="row" class="account-wallet-summary account-section" data-testid="donations-account-wallet">
    <span class="account-wallet-summary-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
    <div class="account-wallet-summary-text">
        <span class="account-eyebrow">{{ __('module-donations::messages.current_balance_label') }}</span>
        <h2>{{ $walletView['label'] }}</h2>
    </div>
</x-account-panel>
</x-account-page>
@endsection
