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
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

final class DonationYooMoneyCheckoutTest extends TestCase
{
    use RefreshDonationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function configure(string $mode = 'live'): Admin
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        app(DonationProviderSettings::class)->save($owner, 'yoomoney', true, $mode, ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'checkout-only-private-key',
        ]);

        return $owner;
    }

    private function order(User $player): DonationOrder
    {
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 12, (string) Str::uuid());
        app(DonationPaymentPreparations::class)->prepare($player, $order->order_uuid, 'yoomoney');

        return $order;
    }

    private function enableTrustedGates(): void
    {
        config()->set('donation-payments.checkout_enabled', true);
        config()->set('donation-payments.callbacks_enabled', true);
        config()->set('donation-payments.credits_enabled', true);
        config()->set('wallet.enabled_sources.donations', true);
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'live', 'pilot_user_id' => null]);
    }

    public function test_pilot_mode_allows_only_selected_player_without_changing_callback_gates(): void
    {
        $this->configure();
        $pilot = User::factory()->create();
        $other = User::factory()->create();
        $pilotOrder = $this->order($pilot);
        $otherOrder = $this->order($other);
        $this->enableTrustedGates();
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'pilot', 'pilot_user_id' => $pilot->getKey()]);

        $this->actingAs($pilot)->get('/modules/donations/orders/'.$pilotOrder->order_uuid.'/checkout')->assertOk();
        $this->actingAs($other)->get('/modules/donations/orders/'.$otherOrder->order_uuid.'/checkout')->assertNotFound();
        $this->actingAs($other)->get('/modules/donations/orders/'.$otherOrder->order_uuid)
            ->assertOk()->assertDontSee('data-testid="donations-start-checkout"', false);
        $this->actingAs($other)->get('/modules/donations')->assertOk()
            ->assertDontSee('name="paymentType"', false);
        $this->assertDatabaseCount('wallet_entries', 0);

        // A malformed value must never silently widen the allowed audience.
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'pilot', 'pilot_user_id' => null]);
        $this->actingAs($pilot)->get('/modules/donations/orders/'.$pilotOrder->order_uuid.'/checkout')->assertNotFound();
        $this->actingAs($other)->get('/modules/donations/orders/'.$otherOrder->order_uuid.'/checkout')->assertNotFound();

        // Removing the optional pilot restriction permits sales for both accounts;
        // switching pilot users never disables callbacks for already paid orders.
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'live', 'pilot_user_id' => null]);
        $this->actingAs($other)->get('/modules/donations/orders/'.$otherOrder->order_uuid.'/checkout')->assertOk();
    }

    public function test_default_release_never_exposes_a_money_transfer_form_even_with_live_merchant(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $order = $this->order($player);
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid)
            ->assertOk()->assertDontSee('donations-start-checkout')->assertDontSee('quickpay/confirm');
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')
            ->assertNotFound();
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_every_independent_gate_must_be_open_before_rendering_checkout(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $order = $this->order($player);
        foreach (['checkout_enabled', 'callbacks_enabled', 'credits_enabled'] as $disabled) {
            $this->enableTrustedGates();
            config()->set('donation-payments.'.$disabled, false);
            $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')
                ->assertNotFound();
        }
        $this->enableTrustedGates();
        config()->set('wallet.enabled_sources.donations', false);
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')
            ->assertNotFound();
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_trusted_checkout_has_exact_database_amount_and_no_remote_request_or_credit(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $order = $this->order($player);
        $this->enableTrustedGates();
        Http::fake();

        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid)
            ->assertOk()->assertSee('data-testid="donations-start-checkout"', false);
        $response = $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout?sum=0.01&receiver=41009999999999&paymentType=AC');
        $response->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('data-testid="donations-yoomoney-checkout"', false)
            ->assertSee('action="https://yoomoney.ru/quickpay/confirm"', false)
            ->assertSee('name="receiver" value="410012345678901"', false)
            ->assertSee('name="sum" value="120.00"', false)
            ->assertSee('name="label" value="kaevcms-'.$order->order_uuid.'"', false)
            ->assertSee('name="paymentType" value="PC"', false)
            ->assertDontSee('name="paymentType" value="AC"', false)
            ->assertSee('data-testid="donations-checkout-submit"', false);
        // The account avatar dialog retains the escaped current URL. Inspect the
        // external payment form itself, where attacker input must never be used.
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $forms = $xpath->query('//form[@data-testid="donations-checkout-form"]');
        $this->assertNotFalse($forms);
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame('https://yoomoney.ru/quickpay/confirm', $form->getAttribute('action'));
        $this->assertSame('410012345678901', $xpath->evaluate('string(.//input[@name="receiver"]/@value)', $form));
        $this->assertSame('120.00', $xpath->evaluate('string(.//input[@name="sum"]/@value)', $form));
        $this->assertStringNotContainsString('41009999999999', $document->saveHTML($form));
        Http::assertNothingSent();
        $this->assertDatabaseCount('module_donation_payment_receipts', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_reloading_checkout_does_not_duplicate_preparations_or_page_titles(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $order = $this->order($player);
        $this->enableTrustedGates();
        Http::preventStrayRequests();
        foreach (range(1, 2) as $attempt) {
            $response = $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')->assertOk();
            $this->assertSame(1, preg_match_all('/<h1\b/i', $response->getContent()));
        }
        $this->assertDatabaseCount('module_donation_payment_preparations', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
        Http::assertNothingSent();
    }

    public function test_other_users_are_denied_and_test_mode_cannot_emit_a_real_checkout(): void
    {
        $this->configure('test');
        $player = User::factory()->create();
        $stranger = User::factory()->create();
        $order = $this->order($player);
        $this->enableTrustedGates();
        $this->actingAs($stranger)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')->assertNotFound();
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')
            ->assertNotFound();
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_expired_cancelled_or_disabled_merchant_never_renders_a_form(): void
    {
        $owner = $this->configure();
        $player = User::factory()->create();
        $stranger = User::factory()->create();
        $order = $this->order($player);
        $this->enableTrustedGates();
        $url = '/modules/donations/orders/'.$order->order_uuid.'/checkout';
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($player)->get($url)->assertOk();

        app(DonationProviderSettings::class)->save($owner, 'yoomoney', false, 'live', ['RUB']);
        $this->actingAs($player)->get($url)->assertNotFound();
        app(DonationProviderSettings::class)->save($owner, 'yoomoney', true, 'live', ['RUB']);
        DB::table('module_donation_orders')->where('id', $order->id)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($player)->get($url)->assertNotFound();
        DB::table('module_donation_orders')->where('id', $order->id)->update(['expires_at' => now()->addHour()]);
        app(DonationOrders::class)->cancelDraft($player, $order->order_uuid);
        $this->actingAs($player)->get($url)->assertNotFound();
        $this->assertDatabaseCount('wallet_entries', 0);
    }
}
