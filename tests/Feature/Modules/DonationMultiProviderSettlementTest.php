<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedReceiptEnvelope;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationPaymentCheckout;
use KaevCMS\Modules\Donations\Services\DonationLiveActivation;
use KaevCMS\Modules\Donations\Services\DonationPaymentSettlements;
use KaevCMS\Modules\Donations\Services\DonationProviderRegistry;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use RuntimeException;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\Fixtures\Donations\FakeRoutablePaymentProvider;
use Tests\TestCase;

/** Confirms the second provider uses the shared wallet and evidence chain. */
final class DonationMultiProviderSettlementTest extends TestCase
{
    use RefreshDonationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        $this->app->instance(DonationProviderRegistry::class, new DonationProviderRegistry([
            FakeRoutablePaymentProvider::class,
            \KaevCMS\Modules\Donations\Providers\YooMoneyProvider::class,
        ]));
        $this->app->forgetInstance(DonationProviderSettings::class);
        $this->app->forgetInstance(DonationPaymentPreparations::class);
        $this->app->forgetInstance(DonationPaymentSettlements::class);
        config()->set('donation-payments.credits_enabled', true);
        config()->set('wallet.enabled_sources.donations', true);
    }

    /** @return array{0:Admin,1:User,2:\KaevCMS\Modules\Donations\Models\DonationOrder} */
    private function prepared(string $mode = 'live'): array
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('USD', '1', true, $owner);
        app(DonationProviderSettings::class)->save($owner, 'fixture_gamma', true, $mode, ['USD'], [
            'api_secret' => 'fixture-provider-secret',
        ]);
        $player = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($player, 'USD', 12, (string) Str::uuid());
        $preparation = app(DonationPaymentPreparations::class)->prepare($player, $order->order_uuid, 'fixture_gamma');
        $this->assertSame('gamma-'.$order->order_uuid, $preparation->provider_label);

        return [$owner, $player, $order];
    }

    private function callback(string $orderUuid, string $operation = 'GAMMA_1', int $amount = 1200): ProviderCallback
    {
        $reference = 'gamma-'.$orderUuid;
        $signature = hash_hmac('sha256', $reference.'|'.$operation.'|'.$amount, 'fixture-provider-secret');

        return new ProviderCallback(http_build_query([
            'reference' => $reference, 'operation' => $operation, 'amount' => (string) $amount,
            'signature' => $signature,
        ]), [], '');
    }

    public function test_two_providers_remain_independent_and_second_provider_credits_once(): void
    {
        [$owner, , $order] = $this->prepared();
        $this->assertSame('GET', app(DonationPaymentPreparations::class)->forOrder($order)->checkout_method);
        // The original YooMoney callback URL never auto-routes to a different driver.
        $this->post('/payments/yoomoney/notify', [])->assertStatus(400);
        $service = app(DonationPaymentSettlements::class);
        $signed = $this->callback($order->order_uuid);
        $this->assertSame('credited', $service->receive($signed, 'fixture_gamma'));
        $this->assertSame('credited', $service->receive($signed, 'fixture_gamma'));
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseCount('module_donation_payment_settlements', 1);
        $this->assertDatabaseHas('module_donation_orders', [
            'order_uuid' => $order->order_uuid, 'status' => 'credited', 'provider_code' => 'fixture_gamma',
        ]);
        $this->assertDatabaseHas('module_donation_payment_receipts', [
            'provider_code' => 'fixture_gamma', 'provider_payment_id' => 'GAMMA_1', 'status' => 'credited',
        ]);
        app(DonationProviderSettings::class)->save($owner, 'fixture_gamma', false, 'live', ['USD']);
        $this->assertSame('credited', $service->receive($signed, 'fixture_gamma'));
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_shared_checkout_supports_second_provider_and_preserves_server_pricing(): void
    {
        [$owner, $player, $order] = $this->prepared();
        $this->actingAs($owner, 'admin');
        app(DonationLiveActivation::class)->update($owner, 'live', null);
        $checkout = app(DonationPaymentCheckout::class)->build($player, $order);
        $this->assertSame('GET', $checkout->method);
        $this->assertSame('https://payments.example.test/checkout', $checkout->redirectUrl);
        $this->assertSame('gamma-'.$order->order_uuid, $checkout->fields['reference']);
        $this->assertSame((string) $order->total_minor, $checkout->fields['amount']);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_pending_receipt_with_new_provider_survives_rotation_and_uses_v2_evidence(): void
    {
        [$owner, , $order] = $this->prepared();
        config()->set('donation-payments.credits_enabled', false);
        $service = app(DonationPaymentSettlements::class);
        $this->assertSame('pending', $service->receive($this->callback($order->order_uuid), 'fixture_gamma'));
        $evidence = DB::table('module_donation_payment_receipts')->value('encrypted_notification');
        $restored = VerifiedReceiptEnvelope::restore(Crypt::decryptString((string) $evidence));
        if ($restored === null) {
            throw new RuntimeException('Authenticated receipt missing.');
        }
        $this->assertSame('fixture_gamma', $restored['providerCode']);
        $this->assertSame('USD', $restored['verified']->currencyCode);
        app(DonationProviderSettings::class)->save($owner, 'fixture_gamma', false, 'live', ['USD'], [
            'api_secret' => 'rotated-provider-secret',
        ]);
        config()->set('donation-payments.credits_enabled', true);
        $this->assertSame(1, $service->retryPending());
        $this->assertSame(0, $service->retryPending());
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_forged_or_mismatched_callbacks_fail_closed_without_wallet_entries(): void
    {
        [, , $order] = $this->prepared('test');
        $service = app(DonationPaymentSettlements::class);
        $this->assertSame('test', $service->receive($this->callback($order->order_uuid), 'fixture_gamma'));
        $this->assertDatabaseCount('wallet_entries', 0);
        $forged = $this->callback($order->order_uuid, 'GAMMA_2', 1201);
        $forged = new ProviderCallback(str_replace('amount=1201', 'amount=1200', $forged->rawBody), [], '');
        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->receive($forged, 'fixture_gamma');
        } finally {
            $this->assertDatabaseCount('wallet_entries', 0);
        }
    }
}
