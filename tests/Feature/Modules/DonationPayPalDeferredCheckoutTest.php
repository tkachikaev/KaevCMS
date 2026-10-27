<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentCheckout;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationProviderRegistry;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use LogicException;
use Tests\Concerns\RefreshDonationIntegrationDatabase;
use Tests\Fixtures\Donations\FakeDeferredPayPalProvider;
use Tests\TestCase;

/** Must not wrap tests in a global SQL transaction: it verifies remote I/O after commit. */
final class DonationPayPalDeferredCheckoutTest extends TestCase
{
    use RefreshDonationIntegrationDatabase;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        $this->owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $this->owner);
        app(DonationRates::class)->saveRate('USD', '1.00', true, $this->owner);
        config(['donation-payments.checkout_enabled' => true,
            'donation-payments.callbacks_enabled' => true, 'donation-payments.credits_enabled' => true,
            'wallet.enabled_sources.donations' => true]);
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'live', 'pilot_user_id' => null]);
        Http::preventStrayRequests();
    }

    public function test_deferred_provider_only_creates_after_sql_commit_and_not_on_read_only_pages(): void
    {
        FakeDeferredPayPalProvider::$remoteCreates = 0;
        $this->app->instance(DonationProviderRegistry::class, new DonationProviderRegistry([
            FakeDeferredPayPalProvider::class,
        ]));
        $this->app->forgetInstance(DonationProviderSettings::class);
        $this->app->forgetInstance(DonationPaymentPreparations::class);
        $this->app->forgetInstance(DonationPaymentCheckout::class);
        app(DonationProviderSettings::class)->save($this->owner, 'paypal', true, 'live', ['USD'], [
            'api_secret' => 'fixture-safe-key',
        ]);
        $user = User::factory()->create();
        $payload = [
            'coins' => 100, 'currency' => 'USD', 'provider' => 'paypal',
            'request_key' => (string) Str::uuid(), 'quoted_total_minor' => '10000',
        ];
        $this->actingAs($user)->postJson('/modules/donations/top-up', $payload)
            ->assertOk()->assertJsonPath('method', 'GET')
            ->assertJsonPath('action', 'https://www.paypal.com/checkoutnow?token=PAYPAL-ORDER-1');
        $this->assertSame(1, FakeDeferredPayPalProvider::$remoteCreates);
        $this->postJson('/modules/donations/top-up', $payload)->assertOk();
        $this->assertSame(1, FakeDeferredPayPalProvider::$remoteCreates);
        $order = app(DonationOrders::class)->forUser($user, DB::table('module_donation_orders')->sole()->order_uuid);
        $this->assertTrue(app(DonationPaymentCheckout::class)->available($user, $order));
        $this->assertSame(1, FakeDeferredPayPalProvider::$remoteCreates);
        $this->assertDatabaseCount('module_donation_paypal_attempts', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
        Http::assertNothingSent();

        try {
            DB::transaction(fn () => app(DonationPaymentCheckout::class)->build($user, $order));
            $this->fail('REST checkout must reject open SQL transactions.');
        } catch (LogicException) {
            $this->assertSame(1, FakeDeferredPayPalProvider::$remoteCreates);
        }
    }
}
