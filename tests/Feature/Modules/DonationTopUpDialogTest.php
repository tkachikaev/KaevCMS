<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Wallet\WalletAppearanceSettings;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

final class DonationTopUpDialogTest extends TestCase
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
        app(DonationRates::class)->saveRate('RUB', '1.25', true, $this->owner);
        app(DonationProviderSettings::class)->save($this->owner, 'yoomoney', true, 'live', ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'private-dialog-fixture-secret',
        ]);
        config(['donation-payments.checkout_enabled' => true, 'donation-payments.callbacks_enabled' => true,
            'donation-payments.credits_enabled' => true, 'wallet.enabled_sources.donations' => true]);
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'live', 'pilot_user_id' => null]);
        Http::preventStrayRequests();
    }

    private function payload(): array
    {
        return ['coins' => 100, 'currency' => 'RUB', 'provider' => 'yoomoney',
            'request_key' => (string) Str::uuid(), 'quoted_total_minor' => '12500'];
    }

    public function test_opening_the_dialog_only_reads_and_has_no_external_payment_form(): void
    {
        $this->actingAs(User::factory()->create())->get('/modules/donations?dialog=1')->assertOk()
            ->assertSee('data-donations-calculator', false)->assertSee('data-donations-provider', false)
            ->assertDontSee('<html', false)->assertDontSee('quickpay/confirm')->assertDontSee('private-dialog-fixture-secret');
        $this->assertDatabaseCount('module_donation_orders', 0);
        $this->assertDatabaseCount('module_donation_payment_preparations', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
        Http::assertNothingSent();
    }

    public function test_submit_returns_only_server_terms_and_retries_keep_one_order(): void
    {
        $player = User::factory()->create();
        $payload = $this->payload() + ['sum' => '0.01', 'receiver' => '41009999999999', 'paymentType' => 'AC'];
        foreach (range(1, 2) as $attempt) {
            $this->actingAs($player)->postJson('/modules/donations/top-up', $payload)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('fields.sum', '125.00')->assertJsonPath('fields.receiver', '410012345678901')
                ->assertJsonPath('fields.paymentType', 'PC')->assertJsonPath('method', 'POST')
                ->assertJsonPath('action', 'https://yoomoney.ru/quickpay/confirm')
                ->assertDontSee('private-dialog-fixture-secret');
        }
        $this->assertDatabaseCount('module_donation_orders', 1);
        $this->assertDatabaseCount('module_donation_payment_preparations', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
        Http::assertNothingSent();
    }

    public function test_dialog_uses_the_wallet_currency_names_and_declensions(): void
    {
        app(WalletAppearanceSettings::class)->update('D', null, [
            'ru' => ['one' => 'дублон', 'few' => 'дублона', 'many' => 'дублонов'],
        ]);
        $this->actingAs(User::factory()->create())->get('/modules/donations?dialog=1&coins=2&currency=RUB')
            ->assertOk()->assertSee('2 дублона')->assertSee('data-many="дублонов"', false)->assertSee('2.50 RUB');
        $this->assertDatabaseCount('module_donation_orders', 0);
    }

    public function test_changed_price_requires_review_without_creating_an_order(): void
    {
        app(DonationRates::class)->saveRate('RUB', '2.00', true, $this->owner);
        $player = User::factory()->create();
        $payload = $this->payload();
        $this->actingAs($player)->postJson('/modules/donations/top-up', $payload)->assertStatus(409)
            ->assertJsonPath('quote.total_minor', 20000)->assertJsonPath('quote.unit_price_minor', 200);
        $this->assertDatabaseCount('module_donation_orders', 0);
        $this->assertDatabaseCount('module_donation_payment_preparations', 0);
        $payload['quoted_total_minor'] = '20000';
        $this->postJson('/modules/donations/top-up', $payload)->assertOk()->assertJsonPath('fields.sum', '200.00');
    }

    public function test_unknown_provider_or_closed_gate_cannot_create_an_order(): void
    {
        $player = User::factory()->create();
        $payload = $this->payload();
        $payload['provider'] = 'unknown';
        $this->actingAs($player)->postJson('/modules/donations/top-up', $payload)->assertUnprocessable();
        $payload['provider'] = 'yoomoney';
        foreach (['checkout_enabled', 'callbacks_enabled', 'credits_enabled'] as $gate) {
            config(['donation-payments.'.$gate => false]);
            $this->postJson('/modules/donations/top-up', $payload)->assertUnprocessable();
            config(['donation-payments.'.$gate => true]);
        }
        app(DonationSettings::class)->updateVisibility(false, $this->owner);
        $this->postJson('/modules/donations/top-up', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('module_donation_orders', 0);
        $this->assertDatabaseCount('module_donation_payment_preparations', 0);
    }

    public function test_closed_order_and_changed_request_contents_cannot_be_reused(): void
    {
        $player = User::factory()->create();
        $payload = $this->payload();
        $this->actingAs($player)->postJson('/modules/donations/top-up', $payload)->assertOk();
        $changed = $payload;
        $changed['coins'] = 99;
        $this->postJson('/modules/donations/top-up', $changed)->assertUnprocessable();
        $order = app(DonationOrders::class)->forUser($player, DB::table('module_donation_orders')->sole()->order_uuid);
        app(DonationOrders::class)->cancelDraft($player, $order->order_uuid);
        $this->postJson('/modules/donations/top-up', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('module_donation_orders', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_guests_and_non_pilot_users_cannot_start_checkout(): void
    {
        $this->postJson('/modules/donations/top-up', $this->payload())->assertUnauthorized();
        $pilot = User::factory()->create();
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'pilot', 'pilot_user_id' => $pilot->id]);
        $this->actingAs(User::factory()->create())->postJson('/modules/donations/top-up', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('module_donation_orders', 0);
    }

    public function test_preparation_audit_failure_rolls_back_the_new_order_too(): void
    {
        AuditLog::creating(static function (AuditLog $entry): void {
            if ($entry->action === 'donations.payment_prepared') {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        try {
            $this->actingAs(User::factory()->create())->postJson('/modules/donations/top-up', $this->payload())->assertStatus(500);
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('module_donation_orders', 0);
        $this->assertDatabaseCount('module_donation_order_events', 0);
        $this->assertDatabaseCount('module_donation_payment_preparations', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
    }
}
