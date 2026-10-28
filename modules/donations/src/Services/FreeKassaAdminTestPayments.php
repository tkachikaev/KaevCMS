<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use KaevCMS\Modules\Donations\Providers\FreeKassaProvider;
use KaevCMS\Modules\Donations\Providers\FreeKassaSignedNotification;
use LogicException;
use RuntimeException;

/**
 * Isolated administrator-only test orders. Never touches DonationOrder, settlements,
 * WalletGateway or player balances. Tests survive module switch-off and mode changes.
 */
final class FreeKassaAdminTestPayments
{
    public function __construct(
        private readonly DonationProviderSettings $settings,
        private readonly AuditLogger $audit,
        private readonly FreeKassaProvider $driver,
    ) {}

    /** @return array{reference:string,url:string} */
    public function start(Admin $admin, string $currency, string $amount): array
    {
        if (! preg_match('/\A(?:[1-9][0-9]?|100)(?:\.[0-9]{1,2})?\z/D', $amount)) {
            throw new InvalidArgumentException('Test payment amount must be between 1 and 100 units.');
        }
        $minor = FreeKassaSignedNotification::parseAmountMinor($amount);
        $uuid = (string) Str::uuid();
        $checkout = DB::transaction(function () use ($admin, $currency, $minor, $uuid) {
            // Lock serializes changes to mode, credentials and creation of a test order.
            $row = DonationProviderSetting::query()->whereKey('freekassa')->lockForUpdate()->first();
            if ($row === null
                || $row->mode !== 'test' || ! in_array($currency, $row->currency_codes ?? [], true)
                || ! in_array($currency, $this->driver->supportedCurrencies(), true)) {
                throw new LogicException('FreeKassa administrative test is not enabled.');
            }
            $credentials = $this->settings->credentialsForDriver('freekassa');
            $this->driver->assertConfiguration($credentials, 'test');
            $senders = $this->settings->freeKassaCallbackPolicy()['senders'];
            if ($senders === []) {
                throw new LogicException('FreeKassa callback allowlist is empty.');
            }
            $url = route('payments.freekassa.notify');
            if (! str_starts_with($url, 'https://') && ! app()->environment('testing')) {
                throw new LogicException('Public HTTPS is required for FreeKassa test checkout.');
            }
            $intent = new PaymentIntent($uuid, 1, $currency, $minor, 2, $url, $url);
            $checkout = $this->driver->beginPayment($intent, $credentials, 'test');
            $this->driver->assertCheckout($checkout, $intent, $credentials, $uuid);
            if ($checkout->redirectUrl !== FreeKassaProvider::CHECKOUT_URL || $checkout->method !== 'GET') {
                throw new LogicException('Unexpected FreeKassa checkout transport.');
            }
            DB::table('module_donation_freekassa_admin_tests')->insert([
                'reference' => $uuid, 'admin_id' => $admin->getKey(),
                'merchant_id' => $credentials['merchant_id'], 'currency_code' => $currency,
                'amount_minor' => $minor, 'status' => 'pending',
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($this->audit->success('module', 'donations.freekassa_test_started', $admin,
                'donations-freekassa-test', ['reference' => $uuid, 'currency' => $currency, 'amount_minor' => $minor]) === null) {
                throw new RuntimeException('Unable to audit test payment creation.');
            }

            return $checkout;
        }, 3);

        // Exact SCI fields from trusted driver; no provider credentials are shown in the admin view.
        return ['reference' => $uuid, 'url' => $checkout->redirectUrl.'?'.http_build_query($checkout->fields, '', '&', PHP_QUERY_RFC3986)];
    }

    /** A null result means the reference is not a test order; ordinary settlement may proceed. */
    public function receiveIfTest(ProviderCallback $callback): ?string
    {
        $reference = FreeKassaSignedNotification::referenceHint($callback);
        $test = DB::table('module_donation_freekassa_admin_tests')->where('reference', $reference)->first();
        if ($test === null) {
            return null;
        }
        // Use the provider's current key. A rotated key while test is in flight
        // requires starting a new test; never trust unsigned callback parameters.
        $credentials = $this->settings->credentialsForDriver('freekassa');
        $verified = FreeKassaSignedNotification::verify(
            $callback, (string) $test->merchant_id, $credentials['secret_2'] ?? '',
            allowZeroTestOperationId: true,
        );
        if (! hash_equals((string) $test->merchant_id, (string) ($credentials['merchant_id'] ?? ''))
            || $verified->orderUuid !== $reference || $verified->amountMinor !== (int) $test->amount_minor) {
            throw new InvalidArgumentException('Mismatched FreeKassa administrative test notification.');
        }
        // Fingerprint normalized signed values, not optional unsigned payer fields.
        $fingerprint = hash('sha256', implode('|', [
            'freekassa-test-v1', $reference, $verified->operationId, $verified->amountMinor,
            $test->merchant_id,
        ]));

        // Zero is a shared test placeholder, not a unique provider operation.
        // Keep it in the signed evidence fingerprint; reserve the unique ID column
        // for actual non-zero IDs. Each test is independently bound by reference.
        $paymentId = $verified->operationId === '0' ? null : $verified->operationId;

        return DB::transaction(function () use ($reference, $verified, $fingerprint, $paymentId): string {
            $row = DB::table('module_donation_freekassa_admin_tests')
                ->where('reference', $reference)->lockForUpdate()->first();
            if ($row === null) {
                throw new InvalidArgumentException('Unknown FreeKassa administrative test.');
            }
            if ($row->status === 'verified') {
                if ($row->provider_payment_id !== $paymentId
                    || ! hash_equals((string) $row->notification_sha256, $fingerprint)) {
                    throw new LogicException('Conflicting FreeKassa administrative test operation.');
                }

                return 'verified';
            }
            if ($row->status !== 'pending') {
                throw new LogicException('Invalid FreeKassa administrative test state.');
            }
            DB::table('module_donation_freekassa_admin_tests')->where('id', $row->id)->update([
                'status' => 'verified', 'provider_payment_id' => $paymentId,
                'notification_sha256' => $fingerprint, 'verified_at' => now(), 'updated_at' => now(),
            ]);
            if ($this->audit->system('module', 'donations.freekassa_test_verified', 'donations-freekassa-test', [
                'reference' => $reference, 'provider_payment_id' => $verified->operationId,
            ]) === null) {
                throw new RuntimeException('Unable to audit verified test notification.');
            }

            // Never call DonationPaymentSettlements, WalletGateway or any player service.
            return 'verified';
        }, 3);
    }

    public function clearCompleted(Admin $admin): int
    {
        return DB::transaction(function () use ($admin): int {
            $count = DB::table('module_donation_freekassa_admin_tests')
                ->where('admin_id', $admin->getKey())
                ->where(function ($query): void {
                    $query->where('status', 'verified')->orWhere('expires_at', '<=', now());
                })->delete();
            if ($count > 0 && $this->audit->success('module', 'donations.freekassa_tests_cleared', $admin,
                'donations-freekassa-test', ['count' => $count]) === null) {
                throw new RuntimeException('Unable to audit test history cleanup.');
            }

            return $count;
        }, 3);
    }

    /** @return array<int, object> */
    public function recent(Admin $admin, int $limit = 8): array
    {
        return DB::table('module_donation_freekassa_admin_tests')->where('admin_id', $admin->getKey())->orderByDesc('id')
            ->limit(max(1, min(20, $limit)))->get()->all();
    }
}
