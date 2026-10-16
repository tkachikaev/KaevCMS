@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.orders_title'))
@section('content')
<section class="account-page-heading">
    <div><h1>{{ __('module-donations::messages.orders_title') }}</h1><p>{{ __('module-donations::messages.orders_description') }}</p></div>
    @if($canChooseCoins)
        <div class="account-form-actions">
            <a wire:navigate class="account-button primary" data-testid="donations-choose-coins" href="{{ route('modules.donations.index') }}">{{ __('module-donations::messages.choose_coins') }}</a>
        </div>
    @else
        <p>{{ __('module-donations::messages.player_topups_closed') }}</p>
    @endif
</section>
<section class="account-section" data-testid="donations-player-orders">
    @forelse($orders as $order)
        <div class="account-wallet-summary">
            <div class="account-wallet-summary-text">
                <a wire:navigate href="{{ route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]) }}">{{ $order->created_at->format('Y-m-d H:i') }} · {{ $order->coins }} · {{ $order->currency_code }}</a>
                <p>{{ $money->format((int) $order->total_minor, (int) $order->exponent) }} {{ $order->currency_code }} — {{ __('module-donations::messages.status_'.$order->displayStatus()) }}</p>
            </div>
        </div>
    @empty
        <p>{{ __('module-donations::messages.no_orders') }}</p>
    @endforelse
    {{ $orders->links() }}
</section>
@endsection
