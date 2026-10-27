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
use KaevCMS\Modules\Donations\Services\DonationLiveActivation;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationPaymentSettlements;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

/** Live merchant API is always mocked. No real charge or external network. */
final class DonationFreeKassaLivePaymentTest extends TestCase
{
    use RefreshDonationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        config()->set('donation-payments.checkout_enabled', true);
        config()->set('donation-payments.callbacks_enabled', true);
        config()->set('donation-payments.credits_enabled', true);
        config()->set('wallet.enabled_sources.donations', true);
    }

    private function prepared(): array
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        app(DonationProviderSettings::class)->save($owner, 'freekassa', true, 'live', ['RUB'], [
            'merchant_id' => '7012', 'secret_1' => 'checkout-provider-secret',
            'secret_2' => 'signed-callback-secret', 'api_key' => 'merchant-orders-api-key',
        ], ['198.51.100.19'], []);
        $player = User::factory()->create();
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'live', 'pilot_user_id' => null]);
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 12, (string) Str::uuid());
        $preparation = app(DonationPaymentPreparations::class)->prepare($player, $order->order_uuid, 'freekassa');
        $this->assertSame('live', $preparation->mode);

        return [$owner, $player, $order];
    }

    private function callback(string $uuid, string $amount = '120.00', string $operation = '888999'): array
    {
        $raw = http_build_query([
            'MERCHANT_ID' => '7012', 'AMOUNT' => $amount, 'intid' => $operation,
            'MERCHANT_ORDER_ID' => $uuid, 'CUR_ID' => '4',
            'SIGN' => md5('7012:'.$amount.':signed-callback-secret:'.$uuid),
        ]);

        return [$raw, ['REMOTE_ADDR' => '198.51.100.19', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTPS' => 'on']];
    }

    private function fakePaid(string $uuid, string $operation = '888999', string $status = 'paid'): void
    {
        $api = ['type' => 'success', 'orders' => [[
            'merchant_order_id' => $uuid, 'fk_order_id' => (int) $operation,
            'amount' => 120.00, 'currency' => 'RUB', 'status' => $status === 'paid' ? 1 : 0,
        ]]];
        Http::fake(['https://api.fk.life/v1/orders' => Http::response($api, 200)]);
    }

    public function test_live_checkout_is_get_and_never_exposes_api_secret(): void
    {
        [, $player, $order] = $this->prepared();
        $response = $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')->assertOk();
        $response->assertSee('https://pay.fk.money/', false)->assertSee('name="oa"', false)
            ->assertSee('name="currency"', false)->assertDontSee('merchant-orders-api-key');
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_paid_api_proof_credits_once_and_duplicate_callback_is_safe_after_disabling_provider(): void
    {
        [$owner, , $order] = $this->prepared();
        $this->fakePaid($order->order_uuid);
        [$body, $server] = $this->callback($order->order_uuid);
        $this->call('POST', '/payments/freekassa/notify', [], [], [], $server, $body)->assertOk()->assertSeeText('YES');
        $this->call('POST', '/payments/freekassa/notify', [], [], [], $server, $body)->assertOk()->assertSeeText('YES');
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseCount('module_donation_payment_settlements', 1);
        app(DonationProviderSettings::class)->save($owner, 'freekassa', false, 'live', ['RUB']);
        $this->call('POST', '/payments/freekassa/notify', [], [], [], $server, $body)->assertOk()->assertSeeText('YES');
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_unpaid_api_response_is_never_credited_or_acknowledged(): void
    {
        [, , $order] = $this->prepared();
        $this->fakePaid($order->order_uuid, status: 'unpaid');
        [$body, $server] = $this->callback($order->order_uuid);
        $this->call('POST', '/payments/freekassa/notify', [], [], [], $server, $body)->assertStatus(503);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseCount('module_donation_payment_receipts', 0);
    }

    public function test_invalid_or_missing_api_key_prevents_live_mode(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->expectException(\InvalidArgumentException::class);
        app(DonationProviderSettings::class)->save($owner, 'freekassa', true, 'live', ['RUB'], [
            'merchant_id' => '7012', 'secret_1' => 'checkout-provider-secret',
            'secret_2' => 'signed-callback-secret',
        ]);
    }
}
