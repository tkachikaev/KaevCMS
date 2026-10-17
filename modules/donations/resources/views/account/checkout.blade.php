@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.checkout_title'))
@section('description', __('module-donations::messages.checkout_description'))
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/donations-player.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@section('content')
<x-account-page>
<x-account-panel class="account-section account-donations-card" data-testid="donations-yoomoney-checkout">
    <h1>{{ __('module-donations::messages.checkout_title') }}</h1>
    <p>{{ __('module-donations::messages.order_reference') }}: {{ $order->order_uuid }}</p>
    <p>{{ __('module-donations::messages.checkout_description') }}</p>
    <h2>{{ $money->format((int) $order->total_minor, 2) }} RUB</h2>
    <p>{{ __('module-donations::messages.checkout_coins', ['coins' => $order->coins]) }}</p>
    <p class="account-field-help">{{ __('module-donations::messages.checkout_return_warning') }}</p>
    {{-- Only the receiver/amount/label constructed from trusted DB state go to YooMoney.
         The player may edit HTML; this never authorizes a Wallet credit. --}}
    <form action="{{ $form->redirectUrl }}" method="POST" data-testid="donations-checkout-form" autocomplete="off" data-turbo="false">
        <input type="hidden" name="receiver" value="{{ $form->fields['receiver'] }}">
        <input type="hidden" name="quickpay-form" value="{{ $form->fields['quickpay-form'] }}">
        <input type="hidden" name="label" value="{{ $form->fields['label'] }}">
        <input type="hidden" name="sum" value="{{ $form->fields['sum'] }}">
        <input type="hidden" name="successURL" value="{{ $form->fields['successURL'] }}">
        <div class="account-form-actions">
            <button class="account-button primary" name="paymentType" value="PC" type="submit" data-testid="donations-checkout-wallet">{{ __('module-donations::messages.checkout_wallet') }}</button>
            <button class="account-button secondary" name="paymentType" value="AC" type="submit" data-testid="donations-checkout-card">{{ __('module-donations::messages.checkout_card') }}</button>
        </div>
    </form>
    <a class="account-button secondary" href="{{ route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]) }}">{{ __('module-donations::messages.checkout_back') }}</a>
</x-account-panel>
</x-account-page>
@endsection
