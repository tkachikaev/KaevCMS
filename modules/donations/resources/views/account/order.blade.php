@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.order_title'))
@section('description', __('module-donations::messages.account_description'))
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/donations-player.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@section('content')
<x-account-page>

<x-account-panel class="account-section account-donations-card" data-testid="donations-order-detail">
    <x-account-panel-header><x-slot:actions>
<div class="account-form-actions">
    @if($canChooseCoins)
        <a wire:navigate class="account-button primary" data-testid="donations-choose-coins" href="{{ route('modules.donations.index') }}">{{ __('module-donations::messages.choose_coins') }}</a>
    @endif
    <a wire:navigate class="account-button secondary" href="{{ route('modules.donations.orders.index') }}">{{ __('module-donations::messages.orders_title') }}</a>
</div></x-slot:actions></x-account-panel-header>
    <p>{{ __('module-donations::messages.order_reference') }}: {{ $order->order_uuid }}</p>
    <p>{{ __('module-donations::messages.order_status') }}: {{ __('module-donations::messages.status_'.$order->displayStatus()) }}</p>
    <p>{{ __('module-donations::messages.player_price_breakdown', ['coins' => $order->coins, 'price' => $money->format((int) $order->unit_price_minor, (int) $order->exponent), 'currency' => $order->currency_code]) }}</p>
    <h2>{{ $money->format((int) $order->total_minor, (int) $order->exponent) }} {{ $order->currency_code }}</h2>
    <p>{{ __('module-donations::messages.order_expires_at') }}: {{ $order->expires_at->format('Y-m-d H:i') }}</p>
    @if($canCheckout)
        <a class="account-button primary" data-testid="donations-start-checkout" href="{{ route('modules.donations.orders.checkout', ['orderUuid' => $order->order_uuid]) }}">{{ __('module-donations::messages.checkout_open') }}</a>
    @endif
    @if(!$canCheckout && $canChooseCoins && $order->status === 'draft' && $order->expires_at->isFuture() && $paymentPreparation === null && count($availableProviders) === 1)
        <form method="POST" action="{{ route('modules.donations.orders.prepare', ['orderUuid' => $order->order_uuid]) }}" data-testid="donations-prepare-provider">
            @csrf
            <input type="hidden" name="provider" value="{{ $availableProviders[0]['code'] }}">
            <button class="account-button primary" type="submit">{{ __('module-donations::messages.yoomoney_top_up') }}</button>
        </form>
    @elseif(!$canCheckout && $canChooseCoins && $order->status === 'draft' && $order->expires_at->isFuture() && count($availableProviders) > 1)
        <form method="POST" action="{{ route('modules.donations.orders.prepare', ['orderUuid' => $order->order_uuid]) }}" data-testid="donations-prepare-provider">
            @csrf
            <label for="donations-provider-selection">{{ __('module-donations::messages.preparation_choose') }}</label>
            <select id="donations-provider-selection" name="provider" required>
                @foreach($availableProviders as $provider)
                    <option value="{{ $provider['code'] }}">{{ $provider['name'] }}</option>
                @endforeach
            </select>
            <button class="account-button primary" type="submit">{{ __('module-donations::messages.yoomoney_top_up') }}</button>
        </form>
    @endif
    @if($order->displayStatus() === 'draft' && $order->expires_at->isFuture())
        <form method="POST" action="{{ route('modules.donations.orders.cancel', ['orderUuid' => $order->order_uuid]) }}">
            @csrf
            <button class="account-button secondary" type="submit">{{ __('module-donations::messages.cancel_draft') }}</button>
        </form>
    @endif
    @error('order')<p class="account-field-error" role="alert">{{ $message }}</p>@enderror
</x-account-panel>
</x-account-page>
@endsection
