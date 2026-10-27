@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.order_title'))
@section('description', __('module-donations::messages.order_admin_description'))
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="donations-admin-order-detail">
    <h2>{{ __('module-donations::messages.order_title') }} #{{ $order->id }}</h2>
    <p>{{ __('module-donations::messages.order_reference') }}: {{ $order->order_uuid }}</p>
    <p>{{ __('module-donations::messages.user_id') }}: {{ $order->user?->name ?? ('#'.$order->user_id) }} (#{{ $order->user_id }})</p>
    <p>{{ __('module-donations::messages.order_status') }}: {{ __('module-donations::messages.status_'.$order->displayStatus()) }}</p>
    <p>{{ $order->coins }} × {{ $money->format((int) $order->unit_price_minor, (int) $order->exponent) }} {{ $order->currency_code }} = <strong>{{ $money->format((int) $order->total_minor, (int) $order->exponent) }} {{ $order->currency_code }}</strong></p>
    <p>{{ __('module-donations::messages.order_expires_at') }}: {{ $order->expires_at->format('Y-m-d H:i') }}</p>
    @if($canRecoverFreeKassa)
        <h3>{{ __('module-donations::messages.freekassa_recovery_title') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.freekassa_recovery_help') }}</p>
        @error('freekassa_recovery')<p role="alert">{{ $message }}</p>@enderror
        <form method="POST" action="{{ route('admin.module-pages.donations.orders.freekassa.recovery', ['adminPath' => $adminPath, 'orderUuid' => $order->order_uuid]) }}" class="settings-form" data-testid="freekassa-api-recovery">
            @csrf
            <button type="submit" class="button button-secondary">{{ __('module-donations::messages.freekassa_recovery_start') }}</button>
        </form>
    @endif
    <h3>{{ __('module-donations::messages.receipts_title') }}</h3>
    @forelse($receipts as $receipt)
        <p data-testid="donations-payment-receipt">{{ $receipt->provider_code }} #{{ $receipt->provider_payment_id }} · {{ $receipt->status }}
        @if($receipt->review_reason) ({{ $receipt->review_reason }}) @endif
        · {{ __('module-donations::messages.receipt_gross') }}: {{ $money->format((int) $receipt->payer_amount_minor, (int) $order->exponent) }} {{ $receipt->currency_code }}
        @if($receipt->provider_code === 'freekassa')
            · {{ __('module-donations::messages.freekassa_fee_unknown') }}
        @else
            · {{ __('module-donations::messages.receipt_net') }}: {{ $money->format((int) $receipt->received_amount_minor, (int) $order->exponent) }} {{ $receipt->currency_code }}
            · {{ __('module-donations::messages.receipt_fee') }}: {{ $money->format((int) $receipt->payer_amount_minor - (int) $receipt->received_amount_minor, (int) $order->exponent) }} {{ $receipt->currency_code }}
        @endif</p>
    @empty<p>{{ __('module-donations::messages.no_receipts') }}</p>@endforelse
    <h3>{{ __('module-donations::messages.review_title') }}</h3>
    <p>{{ __('module-donations::messages.review_manual_notice') }}</p>
    @forelse($reviews as $review)
        <p data-testid="donations-review-event">#{{ $review->id }} · {{ $review->created_at }} · {{ $review->admin_name }} · {{ __('module-donations::messages.review_kind_'.$review->kind) }} · #{{ $review->receipt_id }} — {{ $review->reason }}</p>
    @empty
        <p>{{ __('module-donations::messages.review_none') }}</p>
    @endforelse
    @if($canReport && $receipts->where('status', '!=', 'test')->isNotEmpty())
        <form method="POST" action="{{ route('admin.module-pages.donations.orders.reviews.store', ['adminPath' => $adminPath, 'orderUuid' => $order->order_uuid]) }}" class="settings-form" data-testid="donations-review-form">
            @csrf
            <input type="hidden" name="request_uuid" value="{{ $reportRequestUuid }}">
            <div class="form-group"><label for="review-receipt">{{ __('module-donations::messages.review_receipt') }}</label>
                <select id="review-receipt" name="receipt_id" required>
                    @foreach($receipts as $receipt)
                        @if($receipt->status !== 'test')<option value="{{ $receipt->id }}">#{{ $receipt->id }} — {{ $receipt->provider_code }} / {{ $receipt->status }}</option>@endif
                    @endforeach
                </select>
            </div>
            <div class="form-group"><label for="review-kind">{{ __('module-donations::messages.review_kind') }}</label>
                <select id="review-kind" name="kind" required>
                    @foreach(\KaevCMS\Modules\Donations\Services\DonationPaymentReviews::KINDS as $kind)
                        <option value="{{ $kind }}">{{ __('module-donations::messages.review_kind_'.$kind) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group"><label for="review-reason">{{ __('module-donations::messages.review_reason') }}</label>
                <textarea id="review-reason" name="reason" minlength="10" maxlength="1000" required rows="3"></textarea>
            </div>
            @error('review')<p role="alert">{{ $message }}</p>@enderror
            <button type="submit" class="button button-secondary">{{ __('module-donations::messages.review_submit') }}</button>
        </form>
    @endif
    <h3>{{ __('module-donations::messages.recovery_title') }}</h3>
    @forelse($recovery as $candidate)
        <p data-testid="donations-recovery-candidate">#{{ $candidate->provider_operation_id }} · {{ $candidate->provider_status }} — {{ __('module-donations::messages.recovery_review_only') }}</p>
    @empty<p>{{ __('module-donations::messages.no_receipts') }}</p>@endforelse
    <h3>{{ __('module-donations::messages.order_events') }}</h3>
    @forelse($events as $event)<p>{{ $event->created_at }} · {{ $event->from_status ?? '—' }} → {{ $event->to_status }} ({{ $event->kind }})</p>@empty<p>{{ __('module-donations::messages.no_orders') }}</p>@endforelse
    {{ $events->links() }}
</section>
@endsection
