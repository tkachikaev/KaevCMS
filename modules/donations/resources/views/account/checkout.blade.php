@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.checkout_title'))
@section('description', __('module-donations::messages.checkout_description'))
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/donations-player.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@section('content')
<x-account-page>
<x-account-panel class="account-section account-donations-card" data-testid="donations-provider-checkout">
    <p>{{ __('module-donations::messages.order_reference') }}: {{ $order->order_uuid }}</p>
    <p>{{ __('module-donations::messages.checkout_description') }}</p>
    <h2>{{ $money->format((int) $order->total_minor, (int) $order->exponent) }} {{ $order->currency_code }}</h2>
    <p>{{ __('module-donations::messages.checkout_coins', ['coins' => $order->coins]) }}</p>
    <p class="account-field-help">{{ __('module-donations::messages.checkout_return_warning') }}</p>
    {{-- Checkout parameters come only from the trusted provider driver. No browser value is proof of payment. --}}
    @if($form->method === 'GET')
        {{-- Link navigation permits provider redirects without widening our form-action policy. --}}
        <div class="account-form-actions">
            <a class="account-button primary" data-testid="donations-checkout-submit" rel="noreferrer noopener" href="{{ $form->redirectUrl.'?'.http_build_query($form->fields, '', '&', PHP_QUERY_RFC3986) }}">{{ __('module-donations::messages.topup_submit') }}</a>
        </div>
    @else
    <form action="{{ $form->redirectUrl }}" method="{{ $form->method }}" data-testid="donations-checkout-form" autocomplete="off" data-turbo="false">
        @foreach($form->fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <div class="account-form-actions">
            <button class="account-button primary" type="submit" data-testid="donations-checkout-submit">{{ __('module-donations::messages.topup_submit') }}</button>
        </div>
    </form>
    @endif
    <a class="account-button secondary" href="{{ route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]) }}">{{ __('module-donations::messages.checkout_back') }}</a>
</x-account-panel>
</x-account-page>
@endsection
