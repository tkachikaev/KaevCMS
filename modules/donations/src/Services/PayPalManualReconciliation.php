<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Donations\Exceptions\PayPalApiException;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;
use Throwable;

/** Administrator-initiated read-only recovery. It NEVER issues a second Capture POST. */
final class PayPalManualReconciliation
{
    public function __construct(
        private readonly PayPalAdminSettings $settings,
        private readonly PayPalOrdersApi $api,
        private readonly PayPalVerifiedPayments $payments,
    ) {}

    /** @return string credited|pending|review */
    public function check(int $attemptId, int $orderId): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('PayPal reconciliation must run outside SQL transactions.');
        }
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        if ($attempt->purpose !== 'player' || $attempt->environment !== 'live'
            || (int) $attempt->order_id !== $orderId || $attempt->paypal_order_id === null) {
            throw new LogicException('Only a bound Live PayPal attempt with a saved remote order can be reconciled.');
        }
        try {
            // GET-only read. This enforces the original merchant/config identity.
            $credentials = $this->settings->credentials('live');
            $this->api->assertAttempt($credentials, $attempt);
            $order = $this->api->showOrder($credentials, (string) $attempt->paypal_order_id);
            $units = $order['purchase_units'] ?? null;
            $unit = is_array($units) && count($units) === 1 ? $units[0] : null;
            if (! is_array($unit) || ($unit['invoice_id'] ?? null) !== $attempt->invoice_id
                || ($unit['payee']['merchant_id'] ?? null) !== $attempt->expected_payee_id
                || ($unit['amount']['currency_code'] ?? null) !== $attempt->currency_code
                || ! PayPalMoney::matches($unit['amount']['value'] ?? null, (int) $attempt->gross_amount_minor, (int) $attempt->exponent)) {
                throw new PayPalApiException('manual_remote_order_mismatch', true);
            }
            $captures = $unit['payments']['captures'] ?? [];
            if (! is_array($captures) || count($captures) > 1) {
                throw new PayPalApiException('manual_multiple_captures', true);
            }
            $capture = $captures[0] ?? null;
            if ($attempt->paypal_capture_id !== null && $capture !== null
                && ($capture['id'] ?? null) !== $attempt->paypal_capture_id) {
                throw new PayPalApiException('manual_capture_id_mismatch', true);
            }
            if (($order['status'] ?? null) === 'COMPLETED' && is_array($capture)
                && ($capture['status'] ?? null) === 'COMPLETED') {
                // The common, independently verified settlement validates GET Capture,
                // immutable amounts, payee and Wallet idempotency before crediting.
                $result = $this->payments->reconcile($attemptId);
                $this->saveState($attemptId, $result === 'review' ? 'requires_review' : null,
                    $result === 'review' ? 'manual_payment_review' : 'manual_remote_completed');

                return in_array($result, ['credited', 'review'], true) ? $result : 'pending';
            }
            // A non-COMPLETED GET is never grounds for calling Capture again.
            // In particular, a prior timeout remains unresolved even if approval is visible.
            $ambiguous = in_array($attempt->status, ['capturing', 'capture_unknown', 'requires_review', 'capture_completed_unsettled'], true);
            $status = is_array($capture) ? ($capture['status'] ?? '') : '';
            $reason = $ambiguous ? 'manual_capture_unresolved' : ($status === 'PENDING' ? 'manual_capture_pending' : 'manual_not_completed');
            $this->saveState($attemptId, $ambiguous ? 'requires_review' : null, $reason);

            return $ambiguous ? 'review' : 'pending';
        } catch (Throwable $error) {
            // Never hide a successful paid order or replace one merchant's IDs with another's.
            $this->saveState($attemptId, 'requires_review', 'manual_api_unavailable_or_mismatch');
            throw $error;
        }
    }

    private function saveState(int $attemptId, ?string $status, string $diagnostic): void
    {
        DB::transaction(static function () use ($attemptId, $status, $diagnostic): void {
            $attempt = PayPalAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            $values = ['last_checked_at' => now(), 'diagnostic_code' => $diagnostic, 'updated_at' => now()];
            if ($status !== null && ! in_array($attempt->status, ['settled', 'sandbox_verified'], true)) {
                $values['status'] = $status;
            }
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update($values);
        }, 3);
    }
}
