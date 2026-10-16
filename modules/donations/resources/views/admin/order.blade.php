@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.order_title'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-admin-order-detail">
    <h2>{{ __('module-donations::messages.order_title') }} #{{ $order->id }}</h2>
    <p>{{ __('module-donations::messages.order_reference') }}: {{ $order->order_uuid }}</p>
    <p>{{ __('module-donations::messages.user_id') }}: {{ $order->user?->name ?? ('#'.$order->user_id) }} (#{{ $order->user_id }})</p>
    <p>{{ __('module-donations::messages.order_status') }}: {{ __('module-donations::messages.status_'.$order->displayStatus()) }}</p>
    <p>{{ $order->coins }} × {{ $money->format((int) $order->unit_price_minor, (int) $order->exponent) }} {{ $order->currency_code }} = <strong>{{ $money->format((int) $order->total_minor, (int) $order->exponent) }} {{ $order->currency_code }}</strong></p>
    <p>{{ __('module-donations::messages.order_expires_at') }}: {{ $order->expires_at->format('Y-m-d H:i') }}</p>
    <h3>{{ __('module-donations::messages.order_events') }}</h3>
    @forelse($events as $event)<p>{{ $event->created_at }} · {{ $event->from_status ?? '—' }} → {{ $event->to_status }} ({{ $event->kind }})</p>@empty<p>{{ __('module-donations::messages.no_orders') }}</p>@endforelse
    {{ $events->links() }}
</section>
@endsection
