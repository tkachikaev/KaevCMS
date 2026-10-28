<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Exceptions\PayPalApiException;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;
use RuntimeException;

/** Sandbox-only tests: no DonationOrder, DonationPaymentPreparation, User or Wallet. */
final class PayPalAdminTestPayments
{
    public function __construct(
        private readonly PayPalAdminSettings $settings,
        private readonly PayPalOrderOperations $operations,
        private readonly DonationMoney $money,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{url:string,id:int} */
    public function start(Admin $admin, string $currency, string $amount): array
    {
        $credentials = $this->settings->credentials('sandbox');
        $exponent = $this->settings->selectedCurrency($currency);
        $minor = $this->money->parseUnitPrice($amount, $exponent);
        if ($minor < 10 ** $exponent || $minor > 100 * (10 ** $exponent)) {
            throw new InvalidArgumentException('Sandbox test range is 1-100 currency units.');
        }
        $attempt = DB::transaction(function () use ($admin, $currency, $exponent, $minor, $credentials): PayPalAttempt {
            $row = DonationProviderSetting::query()->whereKey('paypal')->lockForUpdate()->firstOrFail();
            if (! in_array($currency, $row->currency_codes ?? [], true)) {
                throw new LogicException('Sandbox currency was disabled.');
            }
            // Reuse an incomplete checkout, preserving its original idempotency keys.
            $existing = PayPalAttempt::query()->where('purpose', 'admin_test')->where('admin_id', $admin->getKey())
                ->where('environment', 'sandbox')->where('merchant_identity', $credentials->merchantIdentity())
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($existing !== null && in_array($existing->status, ['reserved', 'creating', 'created', 'approved', 'create_unknown'], true)) {
                if ($existing->status === 'creating' || $existing->status === 'approved'
                    || ($existing->status === 'create_unknown' && $existing->create_started_at?->lessThanOrEqualTo(now()->subMinutes(350)))) {
                    throw new LogicException('An administrator Sandbox test needs review.');
                }
                if ($existing->currency_code !== $currency || (int) $existing->gross_amount_minor !== $minor) {
                    throw new LogicException('Complete the existing Sandbox test before changing amount.');
                }

                return $existing;
            }
            $attempt = new PayPalAttempt;
            $attempt->forceFill([
                'admin_id' => $admin->getKey(), 'order_id' => null, 'preparation_id' => null,
                'purpose' => 'admin_test', 'environment' => 'sandbox',
                'merchant_identity' => $credentials->merchantIdentity(), 'status' => 'reserved',
                'create_request_id' => (string) Str::uuid(), 'capture_request_id' => (string) Str::uuid(),
                'invoice_id' => 'KC-'.Str::uuid(), 'gross_amount_minor' => $minor,
                'currency_code' => $currency, 'exponent' => $exponent,
            ])->save();
            if ($this->audit->success('module', 'donations.paypal_sandbox_started', $admin,
                'donations-paypal-test', ['attempt_id' => $attempt->id, 'currency' => $currency,
                    'gross_amount_minor' => $minor]) === null) {
                throw new RuntimeException('Unable to audit PayPal Sandbox purchase.');
            }

            return $attempt;
        }, 3);
        $url = $this->operations->create(
            (int) $attempt->id, $credentials,
            route('admin.module-pages.donations.providers.paypal.test.return', ['attemptId' => $attempt->id]),
            route('admin.module-pages.donations.providers.paypal.test.cancel', ['attemptId' => $attempt->id]),
        );
        if ($url === null) {
            throw new LogicException('Sandbox Create is already processing.');
        }

        return ['url' => $url, 'id' => (int) $attempt->id];
    }

    public function findOwn(Admin $admin, int $id): PayPalAttempt
    {
        $attempt = PayPalAttempt::query()->findOrFail($id);
        if ($attempt->purpose !== 'admin_test' || $attempt->environment !== 'sandbox'
            || $attempt->order_id !== null || $attempt->preparation_id !== null
            || (int) $attempt->admin_id !== (int) $admin->getKey()) {
            throw new LogicException('Not an administrator Sandbox attempt.');
        }

        return $attempt;
    }

    public function approvalStatus(Admin $admin, int $id, string $token): string
    {
        $attempt = $this->findOwn($admin, $id);
        if ($attempt->paypal_order_id === null || ! hash_equals((string) $attempt->paypal_order_id, $token)) {
            throw new InvalidArgumentException('PayPal return token mismatch.');
        }

        return $this->operations->checkApproval($id, $this->settings->credentials('sandbox'));
    }

    /** Only an explicit admin POST triggers Capture, never a browser GET. */
    public function capture(Admin $admin, int $id): ?string
    {
        $attempt = $this->findOwn($admin, $id);
        if ($attempt->paypal_order_id === null) {
            throw new LogicException('PayPal test has no remote order.');
        }
        $response = $this->operations->capture($id, $this->settings->credentials('sandbox'));
        $status = $response['purchase_units'][0]['payments']['captures'][0]['status'] ?? null;
        if ($response !== null && ! in_array($status, ['COMPLETED', 'PENDING', 'DENIED', 'DECLINED'], true)) {
            throw new PayPalApiException('sandbox_capture_status_missing', true);
        }

        return $status;
    }

    /** @return array<int,PayPalAttempt> */
    public function recent(int $limit = 10): array
    {
        return PayPalAttempt::query()->where('purpose', 'admin_test')->where('environment', 'sandbox')
            ->whereNull('order_id')->whereNull('preparation_id')->orderByDesc('id')
            ->limit(min(20, max(1, $limit)))->get()->all();
    }

    /** @return array{state:string,reference:?string,summary:string} */
    public function latestStatus(): array
    {
        $attempt = $this->recent(1)[0] ?? null;
        if ($attempt === null) {
            return ['state' => 'none', 'reference' => null, 'summary' => ''];
        }
        $state = match (true) {
            $attempt->status === 'capture_completed_unsettled' => 'verified',
            in_array($attempt->status, ['capture_denied', 'capture_rejected', 'capture_unknown', 'requires_review', 'approval_reversed'], true) => 'error',
            $attempt->created_at?->lessThan(now()->subMinutes(30)) => 'expired',
            default => 'pending',
        };

        return ['state' => $state, 'reference' => (string) $attempt->invoice_id,
            'summary' => $this->money->format((int) $attempt->gross_amount_minor, (int) $attempt->exponent).' '.$attempt->currency_code];
    }
}
