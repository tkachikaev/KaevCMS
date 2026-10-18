<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Services\DonationLiveActivation;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

final class DonationSimplePaymentFlowTest extends TestCase
{
    use RefreshDonationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function ownerWithRubPrice(): Admin
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin');
        app(DonationRates::class)->saveRate('RUB', '1.25', true, $owner);

        return $owner;
    }

    public function test_player_never_sees_unpayable_test_request_button_when_payments_are_off(): void
    {
        $owner = $this->ownerWithRubPrice();
        app(DonationSettings::class)->updateVisibility(true, $owner);
        $player = User::factory()->create();
        $this->actingAs($player)->get('/modules/donations')->assertOk()
            ->assertDontSee('Создать неоплачиваемую тестовую заявку')
            ->assertDontSee('donations-create-order')
            ->assertSee('Пополнение сейчас недоступно');
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_admin_can_save_visible_wallet_and_one_payment_switch_without_env_edits(): void
    {
        $owner = $this->ownerWithRubPrice();
        $url = '/admin/extensions/donations/providers/yoomoney';
        $this->put($url, [
            'payment_enabled' => '1',
            'credentials' => ['receiver' => '410012345678901', 'notification_secret' => 'sample-hmac-secret-123'],
        ])->assertRedirect();
        $this->assertSame('live', app(DonationLiveActivation::class)->state()['mode']);
        $response = $this->get($url)->assertOk()->assertSee('value="410012345678901"', false)
            ->assertSee('data-testid="donations-yoomoney-simple-settings"', false)
            ->assertDontSee('sample-hmac-secret-123');
        $response->assertSee('••••••••');
        $this->assertTrue(app(DonationSettings::class)->isVisibleToPlayers());

        // Leaving the secret blank means keep; stopping new payments does not
        // invalidate existing payment evidence or alter wallet transactions.
        $this->put($url, [
            'payment_enabled' => '0',
            'credentials' => ['receiver' => '410012345678901', 'notification_secret' => ''],
        ])->assertRedirect();
        $this->assertSame('off', app(DonationLiveActivation::class)->state()['mode']);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_one_order_submit_auto_prepares_only_live_provider_and_opens_checkout(): void
    {
        $this->ownerWithRubPrice();
        $url = '/admin/extensions/donations/providers/yoomoney';
        $this->put($url, [
            'payment_enabled' => '1',
            'credentials' => ['receiver' => '410012345678901', 'notification_secret' => 'sample-hmac-secret-123'],
        ])->assertRedirect();
        $player = User::factory()->create();
        $this->actingAs($player)->get('/modules/donations')->assertOk()
            ->assertSee('data-testid="donations-create-order"', false)
            ->assertDontSee('неоплачиваемую тестовую');

        $response = $this->post('/modules/donations/orders', [
            'coins' => 12, 'currency' => 'RUB', 'request_key' => (string) Str::uuid(),
            'sum' => '0.01', 'receiver' => '41009999999999',
        ]);
        $order = DB::table('module_donation_orders')->sole();
        $response->assertRedirect('/modules/donations/orders/'.$order->order_uuid.'/checkout');
        $this->assertSame(1500, (int) $order->total_minor);
        $this->assertDatabaseCount('module_donation_payment_preparations', 1);
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')
            ->assertOk()->assertSee('name="sum" value="15.00"', false)
            ->assertSee('name="receiver" value="410012345678901"', false);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_editor_cannot_activate_payment_using_simplified_endpoint(): void
    {
        $this->ownerWithRubPrice();
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($editor, 'admin')->put('/admin/extensions/donations/providers/yoomoney', [
            'payment_enabled' => '1',
            'credentials' => ['receiver' => '410012345678901', 'notification_secret' => 'secret'],
        ])->assertForbidden();
        $this->assertSame('off', app(DonationLiveActivation::class)->state()['mode']);
    }

    public function test_local_activation_explains_only_actual_blockers_and_keeps_credentials(): void
    {
        $this->ownerWithRubPrice();
        $this->app->instance('env', 'local');
        config(['app.url' => 'http://127.0.0.1:8000', 'app.debug' => false]);
        $url = '/admin/extensions/donations/providers/yoomoney';
        $token = Str::random(40);
        $this->withSession(['_token' => $token])->put($url, [
            '_token' => $token,
            'payment_enabled' => '1',
            'credentials' => ['receiver' => '410012345678901', 'notification_secret' => 'local-readiness-secret-123'],
        ])->assertRedirect($url)->assertSessionHasErrors('provider');

        $response = $this->get($url)->assertOk()
            ->assertSee(__('module-donations::messages.yoomoney_not_ready'))
            ->assertSee('data-payment-blocker="environment"', false)
            ->assertSee('data-payment-blocker="https"', false)
            ->assertSee('data-payment-blocker="database"', false)
            ->assertDontSee('data-payment-blocker="debug"', false)
            ->assertDontSee('data-payment-blocker="rate"', false)
            ->assertDontSee('data-payment-blocker="credentials"', false)
            ->assertDontSee('local-readiness-secret-123');
        $response->assertSee('value="410012345678901"', false);
        $this->assertSame('off', app(DonationLiveActivation::class)->state()['mode']);
        $this->assertDatabaseCount('wallet_entries', 0);

        $this->app->instance('env', 'production');
        config(['app.debug' => true]);
        $this->get($url)->assertOk()
            ->assertSee('data-payment-blocker="debug"', false)
            ->assertDontSee('data-payment-blocker="environment"', false);
    }

    public function test_missing_rub_price_has_its_own_action_and_ready_settings_hide_the_warning(): void
    {
        $owner = $this->ownerWithRubPrice();
        $url = '/admin/extensions/donations/providers/yoomoney';
        $this->put($url, [
            'payment_enabled' => '1',
            'credentials' => ['receiver' => '410012345678901', 'notification_secret' => 'price-readiness-secret-123'],
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertDontSee('data-testid="donations-payment-blockers"', false);

        app(DonationRates::class)->saveRate('RUB', '1.25', false, $owner);
        $this->get($url)->assertOk()
            ->assertSee('data-payment-blocker="rate"', false)
            ->assertSee(__('module-donations::messages.yoomoney_configure_price'))
            ->assertDontSee('data-payment-blocker="https"', false)
            ->assertDontSee('data-payment-blocker="credentials"', false);
    }
}
