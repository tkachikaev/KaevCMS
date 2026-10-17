<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationLiveActivation;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\DonationYooMoneyCheckout;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

final class DonationLiveReadinessTest extends TestCase
{
    use RefreshDonationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function ready(): Admin
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin');
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        app(DonationProviderSettings::class)->save($owner, 'yoomoney', true, 'live', ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'confidential-test-key',
        ]);

        return $owner;
    }

    public function test_fresh_install_is_off_without_environment_variables(): void
    {
        $this->assertSame(['mode' => 'off', 'pilot_user_id' => null], app(DonationLiveActivation::class)->state());
        $this->assertFalse(DonationYooMoneyCheckout::enabled());
        $this->assertTrue(config('donation-payments.callbacks_enabled'));
        $this->assertTrue(config('wallet.enabled_sources.donations'));
        $this->assertSame(1, Artisan::call('kaevcms:donations-readiness'));
        $this->assertStringContainsString('Payment mode selected in Donations admin', Artisan::output());
    }

    public function test_admin_configures_pilot_and_live_without_env_edits(): void
    {
        $owner = $this->ready();
        $pilot = User::factory()->create();
        $other = User::factory()->create();
        $activation = app(DonationLiveActivation::class);
        $activation->update($owner, 'pilot', $pilot->id);
        $this->assertTrue(DonationYooMoneyCheckout::enabled());
        $this->assertTrue(DonationYooMoneyCheckout::allowedForUser($pilot));
        $this->assertFalse(DonationYooMoneyCheckout::allowedForUser($other));
        $activation->update($owner, 'live', null);
        $this->assertTrue(DonationYooMoneyCheckout::allowedForUser($other));
        $activation->update($owner, 'off', null);
        $this->assertFalse(DonationYooMoneyCheckout::enabled());
        $this->assertTrue(config('donation-payments.callbacks_enabled'));
        $this->assertTrue(config('donation-payments.credits_enabled'));
        $this->assertTrue(config('wallet.enabled_sources.donations'));
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_invalid_player_or_missing_provider_cannot_enable(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin');
        $activation = app(DonationLiveActivation::class);
        $this->expectException(InvalidArgumentException::class);
        $activation->update($owner, 'live', null);
    }

    public function test_inactive_player_rejected(): void
    {
        $owner = $this->ready();
        $inactive = User::factory()->create(['is_active' => false]);
        $this->expectException(InvalidArgumentException::class);
        app(DonationLiveActivation::class)->update($owner, 'pilot', $inactive->id);
    }

    public function test_auditor_cannot_change_payment_mode(): void
    {
        $this->ready();
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $this->actingAs($auditor, 'admin');
        $this->expectException(AuthorizationException::class);
        app(DonationLiveActivation::class)->update($auditor, 'live', null);
    }

    public function test_existing_signed_payment_settles_after_new_sales_are_disabled(): void
    {
        $owner = $this->ready();
        $player = User::factory()->create();
        $activation = app(DonationLiveActivation::class);
        $activation->update($owner, 'pilot', $player->id);
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 12, (string) Str::uuid());
        app(DonationPaymentPreparations::class)->prepare($player, $order->order_uuid, 'yoomoney');
        $this->assertTrue(DonationYooMoneyCheckout::allowedForUser($player));
        $activation->update($owner, 'off', null);
        $this->assertFalse(DonationYooMoneyCheckout::enabled());

        $fields = [
            'notification_type' => 'p2p-incoming', 'operation_id' => '50117881',
            'amount' => '117.60', 'withdraw_amount' => '120.00',
            'currency' => '643', 'datetime' => '2026-10-17T13:00:00Z',
            'sender' => '410012345000000', 'codepro' => 'false',
            'label' => 'kaevcms-'.$order->order_uuid, 'unaccepted' => 'false',
            'test_notification' => 'false',
        ];
        ksort($fields, SORT_STRING);
        $fields['sign'] = hash_hmac('sha256', http_build_query($fields, '', '&', PHP_QUERY_RFC3986), 'confidential-test-key');
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $this->call('POST', '/payments/yoomoney/notify', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body)->assertOk();
        $this->call('POST', '/payments/yoomoney/notify', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body)->assertOk();
        $this->assertSame('credited', $order->fresh()->status);
        $this->assertSame(12, $player->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_http_requires_authorized_admin_and_preserves_callback_when_off(): void
    {
        $owner = $this->ready();
        $this->put('/admin/extensions/donations/providers/activation', ['payment_mode' => 'off'])
            ->assertRedirect();
        $this->post('/payments/yoomoney/notify', [], ['Content-Type' => 'application/json'])->assertStatus(400);
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $this->actingAs($auditor, 'admin')->put('/admin/extensions/donations/providers/activation', [
            'payment_mode' => 'live',
        ])->assertForbidden();
        $this->assertSame('off', DB::table('module_donation_live_settings')->where('id', 1)->value('mode'));
    }
}
