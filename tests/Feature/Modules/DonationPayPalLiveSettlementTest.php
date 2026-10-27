<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\PayPalAdminSettings;
use Tests\TestCase;

/** Integration contracts; PayPal HTTP is mocked, no live or Sandbox payments. */
final class DonationPayPalLiveSettlementTest extends TestCase
{
    use DatabaseMigrations;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        URL::forceScheme('https');
        $this->owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $this->owner);
        app(DonationRates::class)->saveRate('USD', '1.00', true, $this->owner);
        config(['donation-payments.checkout_enabled' => true,
            'donation-payments.callbacks_enabled' => true, 'donation-payments.credits_enabled' => true,
            'wallet.enabled_sources.donations' => true]);
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'live', 'pilot_user_id' => null]);
        Http::preventStrayRequests();
    }

    private function configure(bool $live = true): void
    {
        app(PayPalAdminSettings::class)->save($this->owner, ['USD'], [
            'sandbox_client_id' => 'SandboxClientTest', 'sandbox_client_secret' => 'SandboxSecretTest',
            'sandbox_webhook_id' => 'SANDBOXHOOK123',
            'live_client_id' => 'LiveClientTest', 'live_client_secret' => 'LiveSecretTest',
            'live_webhook_id' => 'LIVEHOOK123', 'live_merchant_id' => 'MERCHANTABC123',
        ], $live);
    }

    private function mockPaypal(int &$capturePosts, bool &$validSignature, bool $badPayee = false): void
    {
        Http::fake(function ($request) use (&$capturePosts, &$validSignature, $badPayee) {
            $url = $request->url();
            if (str_ends_with($url, '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'bearerTest123', 'expires_in' => 3600]);
            }
            if (str_ends_with($url, '/v1/notifications/verify-webhook-signature')) {
                return Http::response(['verification_status' => $validSignature ? 'SUCCESS' : 'FAILURE']);
            }
            if (str_ends_with($url, '/v2/checkout/orders')) {
                return Http::response(['id' => 'LIVEORDER123', 'status' => 'PAYER_ACTION_REQUIRED',
                    'links' => [['rel' => 'payer-action', 'href' => 'https://www.paypal.com/checkoutnow?token=LIVEORDER123']]], 201);
            }
            $attempt = DB::table('module_donation_paypal_attempts')->where('purpose', 'player')->first();
            $unit = ['invoice_id' => $attempt?->invoice_id, 'payee' => ['merchant_id' => $badPayee ? 'OTHERPAYEE123' : 'MERCHANTABC123'],
                'amount' => ['currency_code' => 'USD', 'value' => '100.00']];
            $capture = ['id' => 'LIVECAPTURE123', 'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'USD', 'value' => '100.00']];
            if (str_ends_with($url, '/v2/checkout/orders/LIVEORDER123/capture')) {
                $capturePosts++;
                return Http::response(['id' => 'LIVEORDER123', 'status' => 'COMPLETED',
                    'purchase_units' => [[...$unit, 'payments' => ['captures' => [$capture]]]]], 201);
            }
            if (str_ends_with($url, '/v2/checkout/orders/LIVEORDER123')) {
                return Http::response(['id' => 'LIVEORDER123', 'status' => $capturePosts > 0 ? 'COMPLETED' : 'APPROVED',
                    'purchase_units' => [[...$unit, 'payments' => ['captures' => $capturePosts > 0 ? [$capture] : []]]]]);
            }
            if (str_ends_with($url, '/v2/payments/captures/LIVECAPTURE123')) {
                return Http::response([...$capture,
                    'supplementary_data' => ['related_ids' => ['order_id' => 'LIVEORDER123']]]);
            }
            return Http::response(['error' => 'unexpected_api'], 500);
        });
    }

    private function createPlayerAttempt(): array
    {
        $player = User::factory()->create();
        $this->actingAs($player)->postJson('/modules/donations/top-up', [
            'coins' => 100, 'currency' => 'USD', 'provider' => 'paypal',
            'request_key' => (string) Str::uuid(), 'quoted_total_minor' => '10000',
        ])->assertOk()->assertJsonPath('method', 'GET')
            ->assertJsonPath('action', 'https://www.paypal.com/checkoutnow?token=LIVEORDER123');
        $order = DB::table('module_donation_orders')->sole();
        return [$player, $order];
    }

    public function test_live_checkout_only_credits_after_remote_capture_and_repeated_return_never_posts_twice(): void
    {
        $this->configure();
        $capturePosts = 0;
        $signed = true;
        $this->mockPaypal($capturePosts, $signed);
        [$player, $order] = $this->createPlayerAttempt();
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/paypal-return?token=WRONG')->assertNotFound();
        $this->assertDatabaseCount('wallet_entries', 0);
        $url = '/modules/donations/orders/'.$order->order_uuid.'/paypal-return?token=LIVEORDER123';
        $this->get($url)->assertRedirect();
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertSame(1, $capturePosts);
        $this->assertSame('credited', DB::table('module_donation_orders')->where('id', $order->id)->value('status'));
        $this->get($url)->assertRedirect();
        $this->assertSame(1, $capturePosts);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseCount('module_donation_payment_receipts', 1);
    }

    public function test_wrong_merchant_cannot_credit_wallet_even_when_capture_is_completed(): void
    {
        $this->configure();
        $capturePosts = 0;
        $signed = true;
        $this->mockPaypal($capturePosts, $signed, true);
        [$player, $order] = $this->createPlayerAttempt();
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/paypal-return?token=LIVEORDER123')
            ->assertRedirect();
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseCount('module_donation_payment_receipts', 0);
    }

    public function test_forged_and_replayed_webhooks_never_duplicate_wallet_credit(): void
    {
        $this->configure();
        $capturePosts = 0;
        $signed = false;
        $this->mockPaypal($capturePosts, $signed);
        $this->createPlayerAttempt();
        $raw = '{ "id":"WH-LIVE-123", "event_type":"CHECKOUT.ORDER.APPROVED", "resource":{"id":"LIVEORDER123"} }';
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-abc',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-10-27T16:00:00Z',
            'HTTP_PAYPAL_CERT_URL' => 'https://api-m.paypal.com/v1/notifications/certs/CERT-abc',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'QUJDREVGR0hJSktMTU4=',
        ];
        $this->call('POST', '/payments/paypal/notify', [], [], [], $headers, $raw)->assertStatus(400);
        $this->assertDatabaseCount('module_donation_paypal_webhook_events', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
        $signed = true;
        $this->call('POST', '/payments/paypal/notify', [], [], [], $headers, $raw)->assertOk();
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseCount('module_donation_paypal_webhook_events', 1);
        $this->call('POST', '/payments/paypal/notify', [], [], [], $headers, $raw)->assertOk();
        $this->assertSame(1, $capturePosts);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    private function webhookHeaders(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-i5',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-10-27T16:00:00Z',
            'HTTP_PAYPAL_CERT_URL' => 'https://api-m.paypal.com/v1/notifications/certs/CERT-abc',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'QUJDREVGR0hJSktMTU4=',
        ];
    }

    public function test_verified_refund_and_dispute_are_information_only_and_deduplicated(): void
    {
        $this->configure();
        $capturePosts = 0;
        $signed = true;
        $this->mockPaypal($capturePosts, $signed);
        [$player, $order] = $this->createPlayerAttempt();
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/paypal-return?token=LIVEORDER123');
        $this->assertDatabaseCount('wallet_entries', 1);

        $headers = $this->webhookHeaders();
        $refund = json_encode(['id' => 'WH-I5-REFUND', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'create_time' => '2026-10-27T16:00:00Z',
            'resource' => ['id' => 'REFUND123', 'links' => [[
                'rel' => 'up', 'method' => 'GET',
                'href' => 'https://api.paypal.com/v2/payments/captures/LIVECAPTURE123',
            ]]]], JSON_THROW_ON_ERROR);
        $signed = false;
        $this->call('POST', '/payments/paypal/notify', [], [], [], $headers, $refund)->assertStatus(400);
        $this->assertDatabaseCount('module_donation_paypal_webhook_events', 0);
        $signed = true;
        $this->call('POST', '/payments/paypal/notify', [], [], [], $headers, $refund)->assertOk();
        $this->call('POST', '/payments/paypal/notify', [], [], [], $headers, $refund)->assertOk();
        // The same refund may arrive with a new PayPal webhook event ID.
        $otherEnvelope = str_replace('WH-I5-REFUND', 'WH-I5-REFUND2', $refund);
        $this->call('POST', '/payments/paypal/notify', [], [], [], $headers, $otherEnvelope)->assertOk();
        $this->assertSame(1, DB::table('admin_notifications')->where('type', 'donation_paypal_notice')->count());
        $this->assertSame(2, DB::table('module_donation_paypal_webhook_events')->count());

        $dispute = json_encode(['id' => 'WH-I5-DISPUTE', 'event_type' => 'CUSTOMER.DISPUTE.CREATED',
            'resource' => ['dispute_id' => 'DISPUTE123', 'disputed_transactions' => [
                ['seller_transaction_id' => 'LIVECAPTURE123'],
            ]]], JSON_THROW_ON_ERROR);
        $this->call('POST', '/payments/paypal/notify', [], [], [], $headers, $dispute)->assertOk();
        $this->assertSame(2, DB::table('admin_notifications')->where('type', 'donation_paypal_notice')->count());
        $this->assertSame(1, $capturePosts);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertSame('credited', DB::table('module_donation_orders')->where('id', $order->id)->value('status'));
        $this->assertSame('settled', DB::table('module_donation_paypal_attempts')->value('status'));
    }

    public function test_signed_foreign_capture_cannot_mark_order_or_send_notification(): void
    {
        $this->configure();
        $capturePosts = 0;
        $signed = true;
        $this->mockPaypal($capturePosts, $signed);
        [$player, $order] = $this->createPlayerAttempt();
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/paypal-return?token=LIVEORDER123');
        $raw = json_encode(['id' => 'WH-I5-FORGED-CORRELATION', 'event_type' => 'PAYMENT.CAPTURE.REVERSED',
            'resource' => ['id' => 'FOREIGNCAPTURE', 'supplementary_data' => ['related_ids' => [
                'order_id' => 'LIVEORDER123',
            ]]]], JSON_THROW_ON_ERROR);
        $this->call('POST', '/payments/paypal/notify', [], [], [], $this->webhookHeaders(), $raw)->assertStatus(400);
        $this->assertDatabaseCount('module_donation_paypal_webhook_events', 0);
        $this->assertSame(0, DB::table('admin_notifications')->where('type', 'donation_paypal_notice')->count());
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_refund_link_must_point_to_real_paypal_capture_without_redirects(): void
    {
        $this->configure();
        $capturePosts = 0;
        $signed = true;
        $this->mockPaypal($capturePosts, $signed);
        [$player] = $this->createPlayerAttempt();
        $this->actingAs($player)->get('/modules/donations/orders/'.DB::table('module_donation_orders')->value('order_uuid').'/paypal-return?token=LIVEORDER123');
        $bad = json_encode(['id' => 'WH-I5-INVALID-UP', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => ['id' => 'REFUND123', 'links' => [[
                'rel' => 'up', 'method' => 'GET',
                'href' => 'https://attacker.example/v2/payments/captures/LIVECAPTURE123',
            ]]]], JSON_THROW_ON_ERROR);
        $this->call('POST', '/payments/paypal/notify', [], [], [], $this->webhookHeaders(), $bad)->assertStatus(400);
        $this->assertDatabaseCount('module_donation_paypal_webhook_events', 0);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_manual_api_recovery_never_reposts_capture_after_timeout_and_is_admin_only(): void
    {
        $this->configure();
        $capturePosts = 0;
        $signed = true;
        $this->mockPaypal($capturePosts, $signed);
        [$player, $order] = $this->createPlayerAttempt();
        $attempt = DB::table('module_donation_paypal_attempts')->sole();
        DB::table('module_donation_paypal_attempts')->where('id', $attempt->id)
            ->update(['status' => 'capture_unknown', 'capture_started_at' => now()]);
        $route = route('admin.module-pages.donations.orders.paypal.recovery', [
            'adminPath' => app(\App\Services\Admin\AdminPathSettings::class)->path(),
            'orderUuid' => $order->order_uuid, 'attemptId' => $attempt->id,
        ]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($editor, 'admin')->post($route)->assertForbidden();
        $this->actingAs($this->owner, 'admin')->post($route)->assertRedirect();
        $this->assertSame('requires_review', DB::table('module_donation_paypal_attempts')->value('status'));
        $this->assertNotNull(DB::table('module_donation_paypal_attempts')->value('last_checked_at'));
        $this->assertSame(0, $capturePosts);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

}
