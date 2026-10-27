<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Exceptions\PayPalApiException;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;

/** No browser or webhook JSON can authorize settlement. Both remote GETs and immutable SQL facts must agree. */
final class PayPalVerifiedPayments
{
    public function __construct(
        private readonly PayPalAdminSettings $settings,
        private readonly PayPalOrdersApi $api,
        private readonly DonationPaymentSettlements $settlements,
    ) {}

    /** @return string|null credited/pending/review or null if capture isn't COMPLETED yet */
    public function reconcile(int $attemptId, ?string $notifiedCaptureId = null): ?string
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('PayPal verification must run outside SQL transactions.');
        }
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        if ($attempt->purpose !== 'player' || $attempt->environment !== 'live'
            || $attempt->paypal_order_id === null || $attempt->order_id === null
            || $attempt->preparation_id === null || $attempt->expected_payee_id === null) {
            throw new LogicException('Sandbox/unbound attempts are never eligible for Wallet.');
        }
        $credentials = $this->settings->credentials('live');
        $this->api->assertAttempt($credentials, $attempt);
        $order = DonationOrder::query()->findOrFail($attempt->order_id);
        $preparation = DonationPaymentPreparation::query()->findOrFail($attempt->preparation_id);
        if ($preparation->provider_code !== 'paypal' || $preparation->mode !== 'live'
            || (int) $preparation->order_id !== (int) $order->id
            || $preparation->provider_label !== $order->order_uuid
            || (int) $order->total_minor !== (int) $attempt->gross_amount_minor
            || (int) $order->exponent !== (int) $attempt->exponent
            || $order->currency_code !== $attempt->currency_code
            || (int) $preparation->payer_amount_minor !== (int) $attempt->gross_amount_minor) {
            throw new LogicException('PayPal immutable order terms mismatch.');
        }
        $remoteOrder = $this->api->showOrder($credentials, $attempt->paypal_order_id);
        $units = $remoteOrder['purchase_units'] ?? null;
        $unit = is_array($units) && count($units) === 1 ? $units[0] : null;
        if (! is_array($unit) || ($unit['invoice_id'] ?? null) !== $attempt->invoice_id
            || ($unit['payee']['merchant_id'] ?? null) !== $attempt->expected_payee_id
            || ($unit['amount']['currency_code'] ?? null) !== $attempt->currency_code
            || ! PayPalMoney::matches($unit['amount']['value'] ?? null, (int) $attempt->gross_amount_minor, (int) $attempt->exponent)) {
            throw new PayPalApiException('paypal_remote_payee_or_order_mismatch', true);
        }
        if (($remoteOrder['status'] ?? null) !== 'COMPLETED') {
            return null;
        }
        $captures = $unit['payments']['captures'] ?? null;
        if (! is_array($captures) || count($captures) !== 1 || ! is_array($captures[0])) {
            throw new PayPalApiException('paypal_remote_capture_missing', true);
        }
        $remote = $captures[0];
        $captureId = $remote['id'] ?? null;
        if (! is_string($captureId) || ! preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $captureId)
            || ($notifiedCaptureId !== null && ! hash_equals($notifiedCaptureId, $captureId))
            || ($attempt->paypal_capture_id !== null && ! hash_equals($attempt->paypal_capture_id, $captureId))) {
            throw new PayPalApiException('paypal_capture_id_mismatch', true);
        }
        if (($remote['status'] ?? null) !== 'COMPLETED'
            || ($remote['amount']['currency_code'] ?? null) !== $attempt->currency_code
            || ! PayPalMoney::matches($remote['amount']['value'] ?? null, (int) $attempt->gross_amount_minor, (int) $attempt->exponent)) {
            return null; // A PENDING/DECLINED capture is never payment proof.
        }
        $capture = $this->api->showCapture($credentials, $captureId);
        if (($capture['status'] ?? null) !== 'COMPLETED'
            || ($capture['amount']['currency_code'] ?? null) !== $attempt->currency_code
            || ! PayPalMoney::matches($capture['amount']['value'] ?? null, (int) $attempt->gross_amount_minor, (int) $attempt->exponent)
            || (isset($capture['supplementary_data']['related_ids']['order_id'])
                && $capture['supplementary_data']['related_ids']['order_id'] !== $attempt->paypal_order_id)
            || (isset($capture['payee']['merchant_id'])
                && $capture['payee']['merchant_id'] !== $attempt->expected_payee_id)) {
            throw new PayPalApiException('paypal_capture_detail_mismatch', true);
        }
        // Bind the canonical capture ID under lock, before shared idempotent settlement.
        DB::transaction(function () use ($attemptId, $captureId): void {
            $locked = PayPalAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($locked->purpose !== 'player' || $locked->environment !== 'live'
                || ($locked->paypal_capture_id !== null && $locked->paypal_capture_id !== $captureId)) {
                throw new LogicException('PayPal capture is bound to a different attempt.');
            }
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                'paypal_capture_id' => $captureId, 'status' => $locked->status === 'settled' ? 'settled' : 'capture_completed_unsettled',
                'last_checked_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        $verified = new VerifiedPayment(
            orderUuid: (string) $order->order_uuid, providerPaymentId: $captureId,
            status: 'received_unsettled', currencyCode: (string) $attempt->currency_code,
            amountMinor: (int) $attempt->gross_amount_minor, receivedAmountMinor: null,
            testNotification: false, environment: 'live',
        );
        $result = $this->settlements->recordVerifiedPayPal($attempt, $verified);
        if ($result === 'credited') {
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)
                ->where('paypal_capture_id', $captureId)->update(['status' => 'settled', 'updated_at' => now()]);
        }
        return $result;
    }
}
