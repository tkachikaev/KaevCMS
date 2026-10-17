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
    <h3>{{ __('module-donations::messages.receipts_title') }}</h3>
    @forelse($receipts as $receipt)
        <p data-testid="donations-payment-receipt">{{ $receipt->provider_code }} #{{ $receipt->provider_payment_id }} · {{ $receipt->status }}
        @if($receipt->review_reason) ({{ $receipt->review_reason }}) @endif
        · {{ $money->format((int) $receipt->payer_amount_minor, 2) }} RUB / {{ $money->format((int) $receipt->received_amount_minor, 2) }} RUB</p>
    @empty<p>{{ __('module-donations::messages.no_receipts') }}</p>@endforelse
    <h3>{{ __('module-donations::messages.recovery_title') }}</h3>
    @forelse($recovery as $candidate)
        <p data-testid="donations-recovery-candidate">#{{ $candidate->provider_operation_id }} · {{ $candidate->provider_status }} — {{ __('module-donations::messages.recovery_review_only') }}</p>
    @empty<p>{{ __('module-donations::messages.no_receipts') }}</p>@endforelse
    <h3>{{ __('module-donations::messages.order_events') }}</h3>
    @forelse($events as $event)<p>{{ $event->created_at }} · {{ $event->from_status ?? '—' }} → {{ $event->to_status }} ({{ $event->kind }})</p>@empty<p>{{ __('module-donations::messages.no_orders') }}</p>@endforelse
    {{ $events->links() }}
</section>
@endsection
