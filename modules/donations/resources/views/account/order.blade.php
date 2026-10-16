@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.order_title'))
@section('content')
<section class="account-page-heading"><div><h1>{{ __('module-donations::messages.order_title') }}</h1><p>{{ __('module-donations::messages.not_available_yet') }}</p></div>
<a wire:navigate class="account-button secondary" href="{{ route('modules.donations.orders.index') }}">{{ __('module-donations::messages.orders_title') }}</a></section>
<section class="account-section" data-testid="donations-order-detail">
    <p>{{ __('module-donations::messages.order_reference') }}: {{ $order->order_uuid }}</p>
    <p>{{ __('module-donations::messages.order_status') }}: {{ __('module-donations::messages.status_'.$order->displayStatus()) }}</p>
    <p>{{ __('module-donations::messages.player_price_breakdown', ['coins' => $order->coins, 'price' => $money->format((int) $order->unit_price_minor, (int) $order->exponent), 'currency' => $order->currency_code]) }}</p>
    <h2>{{ $money->format((int) $order->total_minor, (int) $order->exponent) }} {{ $order->currency_code }}</h2>
    <p>{{ __('module-donations::messages.order_expires_at') }}: {{ $order->expires_at->format('Y-m-d H:i') }}</p>
    @if($order->status === 'draft' && $order->expires_at->isFuture())
        <form method="POST" action="{{ route('modules.donations.orders.cancel', ['orderUuid' => $order->order_uuid]) }}">
            @csrf
            <button class="account-button secondary" type="submit">{{ __('module-donations::messages.cancel_draft') }}</button>
        </form>
    @endif
    @error('order')<p class="account-field-error" role="alert">{{ $message }}</p>@enderror
</section>
@endsection
