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
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\PayPalAttempts;
use LogicException;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

/** No actual PayPal requests: iteration 1 tests stable local IDs and transaction boundaries. */
final class DonationPayPalIteration1Test extends TestCase
{
    use RefreshDonationDatabase;

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

    public function test_short_reservations_keep_stable_request_ids_and_only_one_capture_claim(): void
    {
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'USD', 100, (string) Str::uuid());
        $preparation = new DonationPaymentPreparation;
        $preparation->forceFill([
            'order_id' => $order->id, 'provider_code' => 'paypal', 'mode' => 'live',
            'checkout_method' => 'GET', 'provider_label' => $order->order_uuid,
            'payer_amount_minor' => $order->total_minor, 'currency_code' => 'USD',
        ])->save();

        $attempts = app(PayPalAttempts::class);
        $first = $attempts->reserveLive($preparation, 'merchant_config_a', 'MERCHANTABC123');
        $second = $attempts->reserveLive($preparation, 'merchant_config_a', 'MERCHANTABC123');
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->create_request_id, $second->create_request_id);
        $this->assertSame($first->capture_request_id, $second->capture_request_id);
        $this->assertSame($first->invoice_id, $second->invoice_id);
        $this->assertDatabaseCount('module_donation_paypal_attempts', 1);
        $this->assertTrue($attempts->claimCreate($first->id));
        $this->assertFalse($attempts->claimCreate($first->id));
        $attempts->markCreateUnknown($first->id);
        $this->assertTrue($attempts->claimCreate($first->id)); // SAME key, within 6h
        $attempts->markCreated($first->id, 'REMOTE-ORDER-1');
        $this->assertFalse($attempts->claimCreate($first->id));
        $attempts->markApproved($first->id);
        $this->assertTrue($attempts->claimCapture($first->id));
        $this->assertFalse($attempts->claimCapture($first->id));
        $attempts->markCaptureUnknown($first->id);
        $this->assertFalse($attempts->claimCapture($first->id)); // GET reconciliation only
        $this->assertDatabaseCount('wallet_entries', 0);
        Http::assertNothingSent();

        try {
            $attempts->reserveLive($preparation, 'other_merchant', 'MERCHANTABC123');
            $this->fail('The ambiguous order must not move between merchants.');
        } catch (LogicException) {
            $this->assertDatabaseCount('module_donation_paypal_attempts', 1);
        }
    }

}
