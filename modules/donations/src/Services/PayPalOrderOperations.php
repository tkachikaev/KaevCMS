<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Donations\Data\PayPalApiCredentials;
use KaevCMS\Modules\Donations\Exceptions\PayPalApiException;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;

/**
 * Workflow around short SQL claims. HTTP is never executed with an open SQL transaction.
 * NOT exposed by a route/provider in iteration 2; only service methods + tests.
 * Webhook settlement/Wallet/approved-player return follow in iteration 4.
 */
final class PayPalOrderOperations
{
    public function __construct(
        private readonly PayPalOrdersApi $api,
        private readonly PayPalAttempts $attempts,
    ) {}

    /**
     * Only a RESERVED or safe 6h CREATE_UNKNOWN attempt can be posted.
     * @return string|null Saved redirect URL; null if another worker owns the claim.
     */
    public function create(int $attemptId, PayPalApiCredentials $credentials, string $returnUrl, string $cancelUrl): ?string
    {
        $this->assertOutsideTransaction();
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        $this->api->preflightCreate($credentials, $attempt, $returnUrl, $cancelUrl);
        if ($attempt->approval_url !== null && in_array($attempt->status, ['created', 'approved'], true)
            && PayPalOrdersApi::validApprovalHost($attempt->approval_url, $credentials->environment, (string) $attempt->paypal_order_id)) {
            return $attempt->approval_url;
        }
        // Avoid reserving a POST when OAuth credentials are already unavailable.
        $this->api->accessToken($credentials);
        if (! $this->attempts->claimCreate($attemptId)) {
            return null;
        }
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        try {
            $result = $this->api->createOrder($credentials, $attempt, $returnUrl, $cancelUrl);
        } catch (PayPalApiException $error) {
            if ($error->uncertain) {
                $this->attempts->markCreateUnknown($attemptId, $error->diagnosticCode);
            } else {
                $this->attempts->markCreateRejected($attemptId, $error->diagnosticCode);
            }
            throw $error;
        }
        $this->attempts->markCreated($attemptId, $result['id'], $result['approval_url']);

        return $result['approval_url'];
    }

    /**
     * GET order is necessary evidence for APPROVED, never a browser `return` flag.
     * @return string Remote status (CREATED, APPROVED, COMPLETED, etc.)
     */
    public function checkApproval(int $attemptId, PayPalApiCredentials $credentials): string
    {
        $this->assertOutsideTransaction();
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        $this->api->assertAttempt($credentials, $attempt);
        if ($attempt->paypal_order_id === null || ! in_array($attempt->status, ['created', 'approved'], true)) {
            throw new LogicException('PayPal attempt is not ready for approval checking.');
        }
        $order = $this->api->showOrder($credentials, $attempt->paypal_order_id);
        if (($order['status'] ?? null) === 'APPROVED') {
            $this->assertRemotePurchase($order, $attempt);
            $this->attempts->markApproved($attemptId);
        }

        return (string) $order['status'];
    }

    /**
     * Capture is sent once after authenticated GET shows APPROVED. A timeout
     * permanently blocks further POST for this attempt until read-only reconciliation.
     * @return array<string,mixed>|null Null means another worker owns/finished capture.
     */
    public function capture(int $attemptId, PayPalApiCredentials $credentials): ?array
    {
        $this->assertOutsideTransaction();
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        $this->api->assertAttempt($credentials, $attempt);
        if (! in_array($attempt->status, ['created', 'approved'], true)) {
            return null;
        }
        // Re-check remotely even when we previously saw APPROVED: do not post
        // a capture merely because an older local status still says approved.
        if ($this->checkApproval($attemptId, $credentials) !== 'APPROVED') {
            return null;
        }
        $this->api->accessToken($credentials);
        if (! $this->attempts->claimCapture($attemptId)) {
            return null;
        }
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        try {
            // Create was read-verified and persisted; Capture uses only this order ID + stable key.
            $result = $this->api->captureOrder($credentials, $attempt);
            $this->assertRemotePurchase($result, $attempt);
            $captures = $result['purchase_units'][0]['payments']['captures'] ?? null;
            if (! is_array($captures) || count($captures) !== 1 || ! is_array($captures[0])) {
                throw new PayPalApiException('capture_missing_record', true);
            }
            $captureId = $captures[0]['id'] ?? null;
            $captureStatus = $captures[0]['status'] ?? null;
            if (! is_string($captureId) || ! preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $captureId)
                || ! is_string($captureStatus)
                || ! in_array($captureStatus, ['COMPLETED', 'PENDING', 'DENIED', 'DECLINED'], true)) {
                throw new PayPalApiException('capture_invalid_record', true);
            }
            // Capture amount must agree with the original gross before even recording status.
            $amount = $captures[0]['amount'] ?? null;
            if (! is_array($amount) || ($amount['currency_code'] ?? null) !== $attempt->currency_code
                || ! PayPalMoney::matches($amount['value'] ?? null, (int) $attempt->gross_amount_minor, (int) $attempt->exponent)) {
                throw new PayPalApiException('capture_amount_mismatch', true);
            }
            $this->attempts->markCaptureResponse($attemptId, $captureId, $captureStatus);

            return $result;
        } catch (PayPalApiException $error) {
            if ($error->uncertain) {
                $this->attempts->markCaptureUnknown($attemptId, $error->diagnosticCode);
            } else {
                $this->attempts->markCaptureRejected($attemptId, $error->diagnosticCode);
            }
            throw $error;
        }
    }

    /** Read-only remote lookup for later recovery, NEVER posts or credits Wallet. */
    public function readRemote(int $attemptId, PayPalApiCredentials $credentials): array
    {
        $this->assertOutsideTransaction();
        $attempt = PayPalAttempt::query()->findOrFail($attemptId);
        $this->api->assertAttempt($credentials, $attempt);
        if ($attempt->paypal_order_id === null) {
            throw new LogicException('No saved PayPal order ID is available for lookup.');
        }
        $order = $this->api->showOrder($credentials, $attempt->paypal_order_id);
        $capture = $attempt->paypal_capture_id === null ? null : $this->api->showCapture($credentials, $attempt->paypal_capture_id);

        return ['order' => $order, 'capture' => $capture];
    }

    /** @param array<string,mixed> $order */
    private function assertRemotePurchase(array $order, PayPalAttempt $attempt): void
    {
        $units = $order['purchase_units'] ?? null;
        $unit = is_array($units) && count($units) === 1 ? $units[0] : null;
        if (! is_array($unit) || ($unit['invoice_id'] ?? null) !== $attempt->invoice_id
            || ! is_array($unit['amount'] ?? null)
            || ($unit['amount']['currency_code'] ?? null) !== $attempt->currency_code
            || ! PayPalMoney::matches($unit['amount']['value'] ?? null, (int) $attempt->gross_amount_minor, (int) $attempt->exponent)) {
            throw new PayPalApiException('remote_purchase_mismatch', true);
        }
    }

    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('PayPal HTTP cannot run inside a SQL transaction.');
        }
    }
}
