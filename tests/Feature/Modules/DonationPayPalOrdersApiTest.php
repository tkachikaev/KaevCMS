<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Data\PayPalApiCredentials;
use KaevCMS\Modules\Donations\Exceptions\PayPalApiException;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\PayPalAttempts;
use KaevCMS\Modules\Donations\Services\PayPalOrderOperations;
use KaevCMS\Modules\Donations\Services\PayPalOrdersApi;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/** Contract tests with Http::fake(); no external PayPal calls or Wallet credits. */
final class DonationPayPalOrdersApiTest extends TestCase
{
    // Feature coverage must not wrap remote I/O in a test transaction.
    use DatabaseMigrations;

    private PayPalApiCredentials $credentials;

    private PayPalAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('USD', '1.00', true, $owner);
        Http::preventStrayRequests();
        $this->credentials = new PayPalApiCredentials('live', 'merchant-1', 'secret-1');
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'USD', 100, (string) Str::uuid());
        $preparation = new DonationPaymentPreparation;
        $preparation->forceFill([
            'order_id' => $order->id, 'provider_code' => 'paypal', 'mode' => 'live',
            'checkout_method' => 'GET', 'provider_label' => $order->order_uuid,
            'payer_amount_minor' => $order->total_minor, 'currency_code' => 'USD',
        ])->save();
        $this->attempt = app(PayPalAttempts::class)->reserveLive($preparation, $this->credentials->merchantIdentity());
    }

    private function createResponse(string $host = 'www.paypal.com'): array
    {
        return ['id' => 'PAYPALORDER1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
            ['rel' => 'payer-action', 'method' => 'GET', 'href' => 'https://'.$host.'/checkoutnow?token=PAYPALORDER1'],
        ]];
    }

    private function purchaseUnit(): array
    {
        return ['reference_id' => (string) $this->attempt->order->order_uuid,
            'invoice_id' => $this->attempt->invoice_id,
            'amount' => ['currency_code' => 'USD', 'value' => '100.00']];
    }

    private function fakeApi(?array $create = null, ?array $capture = null, int $createCode = 201): void
    {
        Http::fake([
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'BearerSecret123', 'expires_in' => 3600,
            ]),
            'https://api-m.paypal.com/v2/checkout/orders' => Http::response($create ?? $this->createResponse(), $createCode),
            'https://api-m.paypal.com/v2/checkout/orders/PAYPALORDER1' => Http::response([
                'id' => 'PAYPALORDER1', 'status' => 'APPROVED', 'purchase_units' => [$this->purchaseUnit()],
            ]),
            'https://api-m.paypal.com/v2/checkout/orders/PAYPALORDER1/capture' => Http::response($capture ?? [
                'id' => 'PAYPALORDER1', 'status' => 'COMPLETED', 'purchase_units' => [[
                    ...$this->purchaseUnit(),
                    'payments' => ['captures' => [['id' => 'CAPTURE1', 'status' => 'COMPLETED',
                        'amount' => ['currency_code' => 'USD', 'value' => '100.0']]],
                    ],
                ]],
            ], 201),
            'https://api-m.paypal.com/v2/payments/captures/CAPTURE1' => Http::response([
                'id' => 'CAPTURE1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
            ]),
        ]);
    }

    public function test_create_is_after_commit_idempotent_and_token_is_cached_encrypted(): void
    {
        $this->fakeApi();
        $workflow = app(PayPalOrderOperations::class);
        $url = $workflow->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
        $this->assertSame('https://www.paypal.com/checkoutnow?token=PAYPALORDER1', $url);
        $this->assertSame($url, $workflow->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel'));
        $this->assertSame('created', $this->attempt->fresh()->status);
        $this->assertSame('PAYPALORDER1', $this->attempt->fresh()->paypal_order_id);
        $this->assertNotSame('BearerSecret123', Cache::get($this->credentials->tokenCacheKey()));
        Http::assertSentCount(2); // OAuth + Create, no duplicate POST.
        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/v2/checkout/orders')) {
                return false;
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($this->attempt->create_request_id, $req->header('PayPal-Request-Id')[0] ?? null);
            $this->assertSame($this->attempt->invoice_id, $req['purchase_units'][0]['invoice_id']);
            $this->assertSame('100.00', $req['purchase_units'][0]['amount']['value']);
            $this->assertSame('CAPTURE', $req['intent']);
            $this->assertSame('PAY_NOW', $req['payment_source']['paypal']['experience_context']['user_action']);
            return true;
        });
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_approval_get_and_capture_are_separate_and_exactly_one_post_is_allowed(): void
    {
        $this->fakeApi();
        $workflow = app(PayPalOrderOperations::class);
        $workflow->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
        $this->assertSame('APPROVED', $workflow->checkApproval($this->attempt->id, $this->credentials));
        $result = $workflow->capture($this->attempt->id, $this->credentials);
        $this->assertSame('COMPLETED', $result['status']);
        $this->assertSame('capture_completed_unsettled', $this->attempt->fresh()->status);
        $this->assertSame('CAPTURE1', $this->attempt->fresh()->paypal_capture_id);
        $this->assertNull($workflow->capture($this->attempt->id, $this->credentials));
        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/capture')) {
                return false;
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($this->attempt->capture_request_id, $req->header('PayPal-Request-Id')[0] ?? null);
            return true;
        });
        $this->assertSame('CAPTURE1', $workflow->readRemote($this->attempt->id, $this->credentials)['capture']['id']);
        Http::assertSentCount(7); // OAuth, Create, GET approval twice, POST Capture, GET order, GET capture
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_create_timeout_retries_with_same_id_without_second_attempt(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            if (str_ends_with($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'Token123', 'expires_in' => 3600]);
            }
            if (str_ends_with($request->url(), '/v2/checkout/orders')) {
                $calls++;
                if ($calls === 1) {
                    throw new ConnectionException('network timeout');
                }
                return Http::response($this->createResponse(), 201);
            }
            return Http::response([], 404);
        });
        $service = app(PayPalOrderOperations::class);
        try {
            $service->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
            $this->fail('A timeout must not be interpreted as payment success.');
        } catch (PayPalApiException $e) {
            $this->assertTrue($e->uncertain);
        }
        $this->assertSame('create_unknown', $this->attempt->fresh()->status);
        $this->assertNotNull($service->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel'));
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('module_donation_paypal_attempts', 1);
    }

    public function test_capture_timeout_never_post_retries_and_remains_unknown(): void
    {
        $calls = 0;
        $this->fakeApi();
        $workflow = app(PayPalOrderOperations::class);
        $workflow->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
        $workflow->checkApproval($this->attempt->id, $this->credentials);
        Http::fake(function ($request) use (&$calls) {
            if (str_ends_with($request->url(), '/capture')) {
                $calls++;
                throw new ConnectionException('capture timeout');
            }
            return Http::response([], 404);
        });
        try {
            $workflow->capture($this->attempt->id, $this->credentials);
            $this->fail('Capture timeout must be unknown.');
        } catch (PayPalApiException $e) {
            $this->assertTrue($e->uncertain);
        }
        $this->assertSame('capture_unknown', $this->attempt->fresh()->status);
        $this->assertNull($workflow->capture($this->attempt->id, $this->credentials));
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_mismatched_approval_domain_or_purchase_amount_fail_closed(): void
    {
        $this->fakeApi($this->createResponse('attacker.example'));
        try {
            app(PayPalOrderOperations::class)->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
            $this->fail('Unexpected redirect domain accepted.');
        } catch (PayPalApiException $e) {
            $this->assertSame('approval_link_invalid', $e->diagnosticCode);
        }
        $this->assertSame('create_unknown', $this->attempt->fresh()->status);
        $this->assertNull($this->attempt->fresh()->paypal_order_id);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_deterministic_provider_error_and_wrong_credentials_do_not_recreate(): void
    {
        $this->fakeApi(['name' => 'INVALID_REQUEST'], null, 422);
        $this->expectException(PayPalApiException::class);
        try {
            app(PayPalOrderOperations::class)->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
        } finally {
            $this->assertSame('create_rejected', $this->attempt->fresh()->status);
            $this->assertNull(app(PayPalOrderOperations::class)->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel'));
            $this->assertDatabaseCount('wallet_entries', 0);
        }
    }

    public function test_mismatched_merchant_identity_cannot_issue_remote_requests(): void
    {
        $this->fakeApi();
        try {
            app(PayPalOrderOperations::class)->create($this->attempt->id,
                new PayPalApiCredentials('live', 'other-merchant', 'other-secret'), 'https://example.com/return', 'https://example.com/cancel');
            $this->fail('Another merchant must never operate this attempt.');
        } catch (\LogicException) {
            Http::assertNothingSent();
        }
    }

    public function test_http_500_create_is_unknown_but_known_422_is_rejected(): void
    {
        $this->fakeApi(['name' => 'INTERNAL_SERVER_ERROR'], null, 500);
        try {
            app(PayPalOrderOperations::class)->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
            $this->fail('Remote 500 must not be treated as definitive failure.');
        } catch (PayPalApiException $error) {
            $this->assertTrue($error->uncertain);
            $this->assertSame('create_unknown', $this->attempt->fresh()->status);
        }
    }

    public function test_unavailable_oauth_does_not_reserve_remote_create(): void
    {
        Http::fake(['https://api-m.paypal.com/v1/oauth2/token' => Http::response(['name' => 'AUTHENTICATION_FAILURE'], 401)]);
        try {
            app(PayPalOrderOperations::class)->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
            $this->fail('OAuth must fail closed.');
        } catch (PayPalApiException $error) {
            $this->assertSame('oauth_http_401', $error->diagnosticCode);
            $this->assertSame('reserved', $this->attempt->fresh()->status);
        }
    }

    public function test_preflight_rejects_http_return_url_without_placing_a_claim(): void
    {
        Http::fake();
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(PayPalOrderOperations::class)->create($this->attempt->id, $this->credentials, 'http://example.com/return', 'https://example.com/cancel');
        } finally {
            $this->assertSame('reserved', $this->attempt->fresh()->status);
            Http::assertNothingSent();
        }
    }

    public function test_sandbox_admin_attempt_has_no_wallet_or_cms_user_link(): void
    {
        $sandbox = new PayPalApiCredentials('sandbox', 'sandbox-client', 'sandbox-secret');
        $attempt = new PayPalAttempt;
        $attempt->forceFill([
            'order_id' => null, 'preparation_id' => null, 'purpose' => 'admin_test',
            'environment' => 'sandbox', 'merchant_identity' => $sandbox->merchantIdentity(),
            'status' => 'reserved', 'create_request_id' => (string) Str::uuid(),
            'capture_request_id' => (string) Str::uuid(), 'invoice_id' => 'KC-'.Str::uuid(),
            'gross_amount_minor' => 100, 'currency_code' => 'USD', 'exponent' => 2,
        ])->save();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'SandboxToken', 'expires_in' => 3600]),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'SANDBOX1', 'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=SANDBOX1']],
            ], 201),
        ]);
        $url = app(PayPalOrderOperations::class)->create($attempt->id, $sandbox, 'https://example.com/return', 'https://example.com/cancel');
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=SANDBOX1', $url);
        $this->assertNull($attempt->fresh()->order_id);
        $this->assertNull($attempt->fresh()->preparation_id);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_capture_pending_is_recorded_without_wallet_credit(): void
    {
        $capture = ['id' => 'PAYPALORDER1', 'status' => 'COMPLETED', 'purchase_units' => [[
            ...$this->purchaseUnit(),
            'payments' => ['captures' => [['id' => 'CAPTUREPENDING1', 'status' => 'PENDING',
                'amount' => ['currency_code' => 'USD', 'value' => '100.00']]]],
        ]]];
        $this->fakeApi(null, $capture);
        $workflow = app(PayPalOrderOperations::class);
        $workflow->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
        $workflow->capture($this->attempt->id, $this->credentials);
        $this->assertSame('capture_pending', $this->attempt->fresh()->status);
        $this->assertSame('CAPTUREPENDING1', $this->attempt->fresh()->paypal_capture_id);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_capture_with_wrong_currency_cannot_appear_completed(): void
    {
        $capture = ['id' => 'PAYPALORDER1', 'status' => 'COMPLETED', 'purchase_units' => [[
            ...$this->purchaseUnit(),
            'payments' => ['captures' => [['id' => 'CAPTUREOTHER', 'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '100.00']]]],
        ]]];
        $this->fakeApi(null, $capture);
        $workflow = app(PayPalOrderOperations::class);
        $workflow->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
        try {
            $workflow->capture($this->attempt->id, $this->credentials);
            $this->fail('Wrong capture currency must not be accepted.');
        } catch (PayPalApiException $error) {
            $this->assertSame('capture_amount_mismatch', $error->diagnosticCode);
            $this->assertSame('capture_unknown', $this->attempt->fresh()->status);
            $this->assertDatabaseCount('wallet_entries', 0);
        }
    }

    public function test_approval_get_with_wrong_invoice_cannot_capture(): void
    {
        Http::fake([
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'ValidToken', 'expires_in' => 3600]),
            'https://api-m.paypal.com/v2/checkout/orders' => Http::response($this->createResponse(), 201),
            'https://api-m.paypal.com/v2/checkout/orders/PAYPALORDER1' => Http::response([
                'id' => 'PAYPALORDER1', 'status' => 'APPROVED', 'purchase_units' => [[
                    'invoice_id' => 'wrong-invoice', 'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
                ]],
            ]),
        ]);
        $workflow = app(PayPalOrderOperations::class);
        $workflow->create($this->attempt->id, $this->credentials, 'https://example.com/return', 'https://example.com/cancel');
        try {
            $workflow->capture($this->attempt->id, $this->credentials);
            $this->fail('Mismatched invoice cannot be approved.');
        } catch (PayPalApiException $error) {
            $this->assertSame('remote_purchase_mismatch', $error->diagnosticCode);
            $this->assertSame('created', $this->attempt->fresh()->status);
        }
        $this->assertDatabaseCount('wallet_entries', 0);
    }
}
