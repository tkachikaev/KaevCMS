@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.orders_title'))
@section('description', __('module-donations::messages.orders_description'))
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/donations-player.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@section('content')
<x-account-page>

<x-account-panel class="account-section account-donations-card" data-testid="donations-player-orders">
    <x-account-panel-header>
        @if($canChooseCoins)
            <x-slot:actions><a wire:navigate class="account-button primary" data-testid="donations-choose-coins" href="{{ route('modules.donations.index') }}">{{ __('module-donations::messages.choose_coins') }}</a></x-slot:actions>
        @else
            <x-slot:summary><p>{{ __('module-donations::messages.player_topups_closed') }}</p></x-slot:summary>
        @endif
    </x-account-panel-header>
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
</x-account-panel>
</x-account-page>
@endsection
