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
    @if($paypalAttempts->isNotEmpty())
        <h3>{{ __('module-donations::messages.paypal_recovery_title') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_recovery_help') }}</p>
        @error('paypal_recovery')<p role="alert">{{ $message }}</p>@enderror
        @foreach($paypalAttempts as $attempt)
            <div data-testid="paypal-attempt" class="admin-field-help">
                <strong>#{{ $attempt->id }}</strong> · {{ $attempt->status }}
                · PayPal Order: {{ $attempt->paypal_order_id ?? '—' }}
                · Capture: {{ $attempt->paypal_capture_id ?? '—' }}
                @if($attempt->diagnostic_code) · {{ $attempt->diagnostic_code }} @endif
                @if($attempt->last_checked_at) · {{ $attempt->last_checked_at->format('Y-m-d H:i') }} @endif
                @if($canRecoverPayPal && $attempt->purpose === 'player' && $attempt->environment === 'live' && $attempt->paypal_order_id)
                    <form method="POST" action="{{ route('admin.module-pages.donations.orders.paypal.recovery', ['adminPath' => $adminPath, 'orderUuid' => $order->order_uuid, 'attemptId' => $attempt->id]) }}" data-testid="paypal-api-recovery">
                        @csrf
                        <button type="submit" class="button button-secondary">{{ __('module-donations::messages.paypal_recovery_start') }}</button>
                    </form>
                @endif
            </div>
        @endforeach
    @endif
    @if($paypalNotices->isNotEmpty())
        <h3>{{ __('module-donations::messages.paypal_notice_title') }}</h3>
        <p class="admin-field-help">{{ __('module-donations::messages.paypal_notice_help') }}</p>
        @foreach($paypalNotices as $notice)
            @php
                $noticeKey = match ($notice->event_type) {
                    'PAYMENT.CAPTURE.REFUNDED' => 'paypal_notice_refunded',
                    'PAYMENT.CAPTURE.REVERSED' => 'paypal_notice_reversed',
                    default => 'paypal_notice_dispute',
                };
            @endphp
            <p role="status" data-testid="paypal-notice">
                <strong>{{ __('module-donations::messages.'.$noticeKey) }}</strong>
                · {{ $notice->event_occurred_at ?? $notice->created_at }}
                · PayPal ID: {{ $notice->resource_id ?? $notice->event_id }}
                · Capture: {{ $notice->paypal_capture_id }}
            </p>
        @endforeach
    @endif
    <h3>{{ __('module-donations::messages.receipts_title') }}</h3>
    @forelse($receipts as $receipt)
        <p data-testid="donations-payment-receipt">{{ $receipt->provider_code }} #{{ $receipt->provider_payment_id }} · {{ $receipt->status }}
        @if($receipt->review_reason) ({{ $receipt->review_reason }}) @endif
        · {{ __('module-donations::messages.receipt_gross') }}: {{ $money->format((int) $receipt->payer_amount_minor, (int) $order->exponent) }} {{ $receipt->currency_code }}
        @if($receipt->received_amount_minor !== null && $receipt->provider_code !== 'freekassa')
            · {{ __('module-donations::messages.receipt_net') }}:
            @php($netCurrency = $receipt->received_currency_code ?: $receipt->currency_code)
            @if($netCurrency === $receipt->currency_code)
                {{ $money->format((int) $receipt->received_amount_minor, (int) $order->exponent) }} {{ $netCurrency }}
            @else
                {{ (int) $receipt->received_amount_minor }} {{ $netCurrency }} ({{ __('module-donations::messages.receipt_minor_units') }})
            @endif
        @else
            · {{ __('module-donations::messages.receipt_net') }}: {{ __('module-donations::messages.receipt_unknown') }}
        @endif
        @if($receipt->fee_amount_minor !== null && $receipt->fee_currency_code !== null)
            · {{ __('module-donations::messages.receipt_fee') }}:
            @if($receipt->fee_currency_code === $receipt->currency_code)
                {{ $money->format((int) $receipt->fee_amount_minor, (int) $order->exponent) }} {{ $receipt->fee_currency_code }}
            @else
                {{ (int) $receipt->fee_amount_minor }} {{ $receipt->fee_currency_code }} ({{ __('module-donations::messages.receipt_minor_units') }})
            @endif
        @elseif($receipt->provider_code !== 'freekassa' && $receipt->received_amount_minor !== null
            && ($receipt->received_currency_code === null || $receipt->received_currency_code === $receipt->currency_code))
            · {{ __('module-donations::messages.receipt_fee') }}:
            {{ $money->format((int) $receipt->payer_amount_minor - (int) $receipt->received_amount_minor, (int) $order->exponent) }} {{ $receipt->currency_code }}
        @else
            · {{ __('module-donations::messages.receipt_fee') }}: {{ __('module-donations::messages.receipt_unknown') }}
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
